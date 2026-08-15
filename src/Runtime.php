<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Zacksmash\Outpost\Contracts\RuntimeDriver;

/**
 * Apple container implementation of Outpost's runtime driver contract.
 */
class Runtime implements RuntimeDriver
{
    /**
     * The stable identifier stored in instance manifests.
     */
    public const string DRIVER = 'apple-container';

    /**
     * The exact shared image shipped for this package contract.
     */
    public const string PUBLISHED_IMAGE = 'ghcr.io/zacksmash/outpost:0.5.2';

    /**
     * The OCI label used to advertise the image's runtime mount contract.
     */
    public const string IMAGE_RUNTIME_PATH_LABEL = 'io.github.zacksmash.outpost.runtime-path';

    /**
     * The runtime configuration path required by this package version.
     */
    public const string IMAGE_RUNTIME_PATH = '/etc/outpost';

    /**
     * The timeout applied to long-running container lifecycle operations.
     */
    protected const int TIMEOUT = 600;

    /**
     * The per-probe timeout for a single readiness check, in seconds.
     */
    protected const int READY_TIMEOUT = 5;

    /**
     * Get the stable identifier recorded for this runtime driver.
     */
    public function id(): string
    {
        return self::DRIVER;
    }

    /**
     * Get the installed Apple container CLI version.
     */
    public function version(): string
    {
        $result = $this->runOrFail(
            ['container', '--version'],
            'Unable to run the Apple container CLI',
        );

        if (preg_match('/container CLI version\s+v?([^\s]+)/i', $result->output(), $matches) !== 1) {
            throw new RuntimeException('Unable to determine the Apple container CLI version.');
        }

        return $matches[1];
    }

    /**
     * Get the container system service status.
     */
    public function systemStatus(): string
    {
        $result = $this->runOrFail(
            ['container', 'system', 'status', '--format', 'json'],
            'Unable to inspect the container system status',
        );

        $status = data_get(json_decode($result->output(), true), 'status');

        if (! is_string($status) || $status === '') {
            throw new RuntimeException('Unable to parse the container system status as JSON.');
        }

        return $status;
    }

    /**
     * Start the container system service.
     */
    public function startSystem(): void
    {
        $this->runOrFail(
            ['container', 'system', 'start'],
            'Unable to start the Apple container system',
            self::TIMEOUT,
        );
    }

    /**
     * Stop the container system before changing machine-wide configuration.
     */
    public function stopSystem(): void
    {
        $this->runOrFail(
            ['container', 'system', 'stop'],
            'Unable to stop the Apple container system',
        );
    }

    /**
     * Get the domain the DNS daemon publishes container hostnames under.
     *
     * Read the running service's properties instead of config.toml because
     * edits there do not take effect until the runtime is restarted.
     */
    public function publicationDomain(): ?string
    {
        $result = $this->runOrFail(
            ['container', 'system', 'property', 'list', '--format', 'json'],
            'Unable to inspect the container system properties',
        );

        $properties = json_decode($result->output(), true);

        if (! is_array($properties)) {
            throw new RuntimeException('Unable to parse the container system properties as JSON.');
        }

        $domain = data_get($properties, 'dns.domain');

        return is_string($domain) && $domain !== '' ? rtrim($domain, '.') : null;
    }

    /**
     * Determine if the given local DNS domain is registered.
     */
    public function domainRegistered(string $domain): bool
    {
        $result = $this->runOrFail(
            ['container', 'system', 'dns', 'list'],
            'Unable to list the registered local DNS domains',
        );

        $domains = array_slice(array_map('trim', explode("\n", trim($result->output()))), 1);

        return in_array($domain, $domains, true);
    }

    /**
     * Register a machine-wide local DNS resolver with administrator privileges.
     */
    public function registerDomain(string $domain): void
    {
        if (preg_match(
            '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/Di',
            $domain,
        ) !== 1) {
            throw new RuntimeException('The Outpost domain is not valid for DNS registration.');
        }

        $result = Process::forever()
            ->tty(SymfonyProcess::isTtySupported())
            ->run(['sudo', 'container', 'system', 'dns', 'create', $domain]);

        if (! $result->successful()) {
            // A TTY run writes straight to the terminal, so there may be no
            // captured output to relay.
            $output = trim($result->errorOutput() ?: $result->output());

            throw new RuntimeException(
                "Unable to register the [{$domain}] DNS resolver".($output === '' ? '.' : ": {$output}"),
            );
        }
    }

    /**
     * Determine if the given image exists locally.
     */
    public function hasImage(string $image): bool
    {
        return Process::run(['container', 'image', 'inspect', $image])->successful();
    }

    /**
     * Read the compatibility metadata exposed by Apple container for an image.
     *
     * @return array{digest: string|null, labels: array<string, string>}|null
     */
    public function imageMetadata(string $image): ?array
    {
        $result = Process::run(['container', 'image', 'inspect', $image]);

        if (! $result->successful()) {
            return null;
        }

        $images = json_decode($result->output(), true);

        if (! is_array($images) || ! is_array($images[0] ?? null)) {
            throw new RuntimeException("Unable to parse metadata for the [{$image}] image.");
        }

        $labels = data_get($images, '0.variants.0.config.config.Labels', []);

        if (! is_array($labels)) {
            $labels = [];
        }

        $digest = data_get($images, '0.configuration.descriptor.digest');

        if (! is_string($digest) || preg_match('/^[a-z0-9]+:[a-f0-9]{32,}$/D', $digest) !== 1) {
            $id = data_get($images, '0.id');
            $digest = is_string($id) && preg_match('/^[a-f0-9]{64}$/D', $id) === 1
                ? 'sha256:'.$id
                : null;
        }

        return [
            'digest' => $digest,
            'labels' => array_filter(
                $labels,
                fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
                ARRAY_FILTER_USE_BOTH,
            ),
        ];
    }

    /**
     * Pull an image from an OCI registry, streaming its progress.
     */
    public function pull(string $image, ?callable $output = null): void
    {
        $result = Process::forever()->run(
            ['container', 'image', 'pull', $image],
            $output,
        );

        if (! $result->successful()) {
            throw new RuntimeException(
                "Unable to pull the [{$image}] image: ".trim($result->errorOutput() ?: $result->output()),
            );
        }
    }

    /**
     * Build an image from the given context directory, streaming build output.
     *
     * @param  array<string, string>  $buildArgs
     */
    public function build(string $image, string $dns, string $context, array $buildArgs = [], ?callable $output = null): void
    {
        $command = ['container', 'build', '--dns', $dns, '--tag', $image];

        foreach ($buildArgs as $key => $value) {
            $command[] = '--build-arg';
            $command[] = "{$key}={$value}";
        }

        $command[] = $context;

        $result = Process::forever()->run($command, $output);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Unable to build the [{$image}] image: ".trim($result->errorOutput() ?: $result->output()),
            );
        }
    }

    /**
     * Boot a new detached container.
     *
     * @param  list<string>  $volumes
     * @param  array<string, string>  $environment
     */
    public function boot(
        string $container,
        string $image,
        string $dns,
        array $volumes,
        int $cpus = 4,
        string $memory = '2G',
        ?int $uid = null,
        ?int $gid = null,
        array $environment = [],
    ): void {
        $this->validatedResources($cpus, $memory);

        if (($uid === null) !== ($gid === null)
            || ($uid !== null && ($uid < 1 || $gid < 1))) {
            throw new RuntimeException('The instance user and group IDs must both be positive integers.');
        }

        $command = [
            'container', 'run', '--detach', '--name', $container, '--dns', $dns,
            '--cpus', (string) $cpus, '--memory', $memory,
        ];

        if ($uid !== null) {
            array_push(
                $command,
                '--env', "OUTPOST_UID={$uid}",
                '--env', "OUTPOST_GID={$gid}",
            );
        }

        foreach ($environment as $name => $value) {
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/D', $name) !== 1) {
                throw new RuntimeException("The container environment variable name [{$name}] is invalid.");
            }

            $command[] = '--env';
            $command[] = "{$name}={$value}";
        }

        foreach ($volumes as $volume) {
            $command[] = '--volume';
            $command[] = $volume;
        }

        $command[] = $image;

        $this->runOrFail($command, "Unable to boot the container [{$container}]", self::TIMEOUT);
    }

    /**
     * Validate and normalize configured per-instance resources.
     *
     * @return array{cpus: int, memory: string}
     */
    public function validatedResources(mixed $cpus, mixed $memory): array
    {
        if (! is_int($cpus) || $cpus < 1) {
            throw new RuntimeException('The [outpost.resources.cpus] value must be a positive integer.');
        }

        if (! is_string($memory)
            || preg_match('/^[1-9][0-9]*(?:[KMGTPE](?:I?B)?)?$/Di', $memory) !== 1) {
            throw new RuntimeException(
                'The [outpost.resources.memory] value must be a positive size such as 2048M or 2G.',
            );
        }

        return ['cpus' => $cpus, 'memory' => $memory];
    }

    /**
     * Start the given container.
     */
    public function start(string $container): void
    {
        $this->runOrFail(
            ['container', 'start', $container],
            "Unable to start the container [{$container}]",
        );
    }

    /**
     * Release application processes that wait for provisioning to finish.
     */
    public function releaseProcesses(string $container): void
    {
        $result = $this->exec($container, ['touch', '/var/lib/outpost/ready'], root: true);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Unable to release the application processes in [{$container}]: ".ProcessOutput::combined($result),
            );
        }
    }

    /**
     * Read normalized application process states from Supervisor.
     *
     * @param  list<string>  $processes
     * @return array<string, array{state: string, details: string}>
     */
    public function processStates(string $container, array $processes): array
    {
        $states = [];

        foreach ($processes as $process) {
            $program = $this->supervisorProcess($process);
            $result = $this->exec(
                $container,
                ['supervisorctl', 'status', $program],
                root: true,
            );
            $output = ProcessOutput::combined($result);

            if (preg_match('/\bERROR \(no such process\)/i', $output) === 1) {
                $states[$process] = [
                    'state' => 'missing',
                    'details' => 'Supervisor has no such process.',
                ];

                continue;
            }

            if (preg_match(
                '/^'.preg_quote($program, '/').'\s+(?<state>[A-Z]+)(?:\s+(?<details>.*))?$/D',
                trim($result->output()),
                $matches,
            ) !== 1) {
                if ($result->successful()) {
                    throw new RuntimeException(
                        "Unable to parse the [{$process}] process state reported by Supervisor.",
                    );
                }

                throw new RuntimeException(
                    "Unable to inspect the [{$process}] process in [{$container}]: {$output}",
                );
            }

            $states[$process] = [
                'state' => strtolower($matches['state']),
                'details' => trim($matches['details'] ?? ''),
            ];
        }

        return $states;
    }

    /**
     * Restart one named application process through Supervisor.
     */
    public function restartProcess(string $container, string $process): void
    {
        $result = $this->exec(
            $container,
            ['supervisorctl', 'restart', $this->supervisorProcess($process)],
            root: true,
        );

        $output = ProcessOutput::combined($result);

        if (! $result->successful() || preg_match('/^.*\bERROR\b.*$/mi', $output) === 1) {
            throw new RuntimeException(
                "Unable to restart the [{$process}] process in [{$container}]: {$output}",
            );
        }
    }

    /**
     * Stop the given container.
     */
    public function stop(string $container): void
    {
        $this->runOrFail(
            ['container', 'stop', $container],
            "Unable to stop the container [{$container}]",
        );
    }

    /**
     * Delete the given container, by force when it may still be running.
     */
    public function delete(string $container, bool $force = false): void
    {
        $this->runOrFail(
            ['container', 'delete', ...($force ? ['--force'] : []), $container],
            "Unable to delete the container [{$container}]",
        );
    }

    /**
     * Run a command inside the given container.
     *
     * Callers are responsible for inspecting the returned result. There is
     * no timeout: provisioning steps like a cold composer or npm install
     * legitimately outlast any cap Outpost could pick for them.
     *
     * @param  list<string>  $command
     */
    public function exec(string $container, array $command, bool $root = false): ProcessResult
    {
        return Process::forever()->run($this->execCommand($container, $command, $root));
    }

    /**
     * Stream a non-interactive command inside the given container.
     *
     * @param  list<string>  $command
     */
    public function run(string $container, array $command, ?callable $output = null, bool $root = false): int
    {
        return Process::forever()
            ->run($this->execCommand($container, $command, $root), $output)
            ->exitCode() ?? 1;
    }

    /**
     * Get the state of every container, keyed by container name.
     *
     * @return array<string, string>
     */
    public function states(): array
    {
        $result = $this->runOrFail(
            ['container', 'list', '--all', '--format', 'json'],
            'Unable to list the existing containers',
        );

        $containers = json_decode($result->output(), true);

        if (! is_array($containers)) {
            throw new RuntimeException('Unable to parse the container list output as JSON.');
        }

        $states = [];

        foreach ($containers as $item) {
            $id = data_get($item, 'id');
            $state = data_get($item, 'status.state');

            if (is_string($id) && is_string($state)) {
                $states[$id] = $state;
            }
        }

        return $states;
    }

    /**
     * Get the state of the given container, or null if it does not exist.
     */
    public function state(string $container): ?string
    {
        return $this->states()[$container] ?? null;
    }

    /**
     * Determine if the given container exists in any state.
     */
    public function exists(string $container): bool
    {
        return $this->state($container) !== null;
    }

    /**
     * Determine if the given container is running.
     */
    public function running(string $container): bool
    {
        return $this->state($container) === 'running';
    }

    /**
     * Get the user-facing runtime and provisioning state of an instance.
     */
    public function instanceState(Manifest $manifest, ?string $runtimeState = null): string
    {
        $runtimeState ??= $this->state($manifest->container) ?? 'missing';

        if ($runtimeState !== 'running') {
            return $runtimeState;
        }

        if ($manifest->status === 'provisioning') {
            return 'provisioning';
        }

        if ($manifest->status === 'failed') {
            return 'degraded';
        }

        return 'running';
    }

    /**
     * Get the effective lifecycle status after considering live runtime state.
     */
    public function instanceStatus(Manifest $manifest, ?string $runtimeState = null): string
    {
        $runtimeState ??= $this->state($manifest->container) ?? 'missing';

        if ($runtimeState === 'missing' && $manifest->status === 'ready') {
            return 'degraded';
        }

        return $manifest->status;
    }

    /**
     * Determine if the given container is answering HTTP.
     */
    public function ready(string $container, bool $secure = false): bool
    {
        return $this->exec($container, [
            'curl', '--fail', ...($secure ? ['--insecure'] : []), '--silent', '--output', '/dev/null',
            '--max-time', (string) self::READY_TIMEOUT,
            ($secure ? 'https' : 'http').'://127.0.0.1',
        ])->successful();
    }

    /**
     * Wait for the given container to answer HTTP.
     */
    public function awaitReady(string $container, int $seconds, bool $secure = false): bool
    {
        $attempts = max(1, $seconds);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($this->ready($container, $secure)) {
                return true;
            }

            if ($attempt < $attempts) {
                Sleep::for(1)->second();
            }
        }

        return false;
    }

    /**
     * Open an interactive shell inside the given container.
     *
     * Only request a remote TTY when a local one exists — the CLI's
     * TTY path needs a real terminal to enter raw mode.
     */
    public function shell(string $container, ?callable $output = null, bool $root = false): int
    {
        $tty = SymfonyProcess::isTtySupported();
        $command = $this->execCommand($container, ['bash'], $root);

        array_splice($command, 2, 0, ['-i', ...($tty ? ['-t'] : [])]);

        return Process::forever()
            ->tty($tty)
            ->run($command, $output)
            ->exitCode() ?? 1;
    }

    /**
     * Fetch the given container's service logs.
     *
     * A follow stream ends whenever the container stops or the user
     * interrupts, so only a plain fetch reports failure.
     */
    public function logs(string $container, bool $follow = false, ?callable $output = null): void
    {
        $result = Process::forever()->run([
            'container', 'logs', ...($follow ? ['--follow'] : []), $container,
        ], $output);

        if (! $follow && ! $result->successful()) {
            throw new RuntimeException(
                "Unable to fetch the logs of the container [{$container}]: ".trim($result->errorOutput() ?: $result->output()),
            );
        }
    }

    /**
     * Flush the macOS DNS cache.
     *
     * Recreated containers reuse their names but receive new addresses,
     * and macOS happily keeps serving the stale one until told not to.
     */
    public function flushDnsCache(): void
    {
        $this->runOrFail(
            ['dscacheutil', '-flushcache'],
            'Unable to flush the macOS DNS cache',
        );
    }

    /**
     * Run the given command or throw with its real error output.
     *
     * @param  list<string>  $command
     */
    protected function runOrFail(array $command, string $message, ?int $timeout = null): ProcessResult
    {
        $timeout ??= $this->lifecycleTimeout();

        try {
            $result = Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException $e) {
            throw new RuntimeException(
                "{$message} timed out after {$timeout} seconds. The Apple container VM may be unresponsive. "
                .'Stop unrelated container workloads before restarting Apple container with '
                .'[container system stop && container system start], then retry.',
                previous: $e,
            );
        }

        if (! $result->successful()) {
            throw new RuntimeException(
                "{$message}: ".trim($result->errorOutput() ?: $result->output()),
            );
        }

        return $result;
    }

    /**
     * Build a shell-free exec command as the application user by default.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    protected function execCommand(string $container, array $command, bool $root): array
    {
        $user = $root ? 'root' : 'outpost';
        $home = $root ? '/root' : '/home/outpost';

        $environment = ['--env', "HOME={$home}"];

        // A root command must not leave root-owned files in the shared host
        // caches used by normal provisioning commands.
        if ($root) {
            array_push(
                $environment,
                '--env', 'COMPOSER_CACHE_DIR=/root/.composer/cache',
                '--env', 'NPM_CONFIG_CACHE=/root/.npm',
            );
        }

        return [
            'container', 'exec',
            ...$environment,
            '--user', $user,
            '--workdir', '/app',
            $container,
            ...$command,
        ];
    }

    /**
     * Resolve a repository process name to its private Supervisor program.
     */
    protected function supervisorProcess(string $process): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $process) !== 1) {
            throw new RuntimeException("The [{$process}] process name is invalid.");
        }

        return "outpost-{$process}";
    }

    /**
     * Get the bounded timeout for quick host runtime operations.
     */
    protected function lifecycleTimeout(): int
    {
        $timeout = config('outpost.lifecycle_timeout');

        if (! is_int($timeout) || $timeout < 1) {
            throw new RuntimeException('The [outpost.lifecycle_timeout] value must be a positive integer.');
        }

        return $timeout;
    }
}
