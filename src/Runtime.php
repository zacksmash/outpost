<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;

class Runtime
{
    /**
     * The timeout applied to long-running container operations.
     */
    protected const int TIMEOUT = 600;

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
     * Determine if the given image exists locally.
     */
    public function hasImage(string $image): bool
    {
        return Process::run(['container', 'image', 'inspect', $image])->successful();
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
     */
    public function boot(string $container, string $image, string $dns, array $volumes): void
    {
        $command = ['container', 'run', '--detach', '--name', $container, '--dns', $dns];

        foreach ($volumes as $volume) {
            $command[] = '--volume';
            $command[] = $volume;
        }

        $command[] = $image;

        $this->runOrFail($command, "Unable to boot the container [{$container}]");
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
     * Callers are responsible for inspecting the returned result.
     *
     * @param  list<string>  $command
     */
    public function exec(string $container, array $command): ProcessResult
    {
        return Process::timeout(self::TIMEOUT)->run(['container', 'exec', $container, ...$command]);
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
     * Determine if the given container is answering HTTP.
     */
    public function ready(string $container): bool
    {
        return $this->exec($container, [
            'curl', '--fail', '--silent', '--output', '/dev/null', 'http://127.0.0.1',
        ])->successful();
    }

    /**
     * Wait for the given container to answer HTTP.
     */
    public function awaitReady(string $container, int $seconds): bool
    {
        $attempts = max(1, $seconds);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($this->ready($container)) {
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
    public function shell(string $container, ?callable $output = null): int
    {
        $tty = SymfonyProcess::isTtySupported();

        return Process::forever()
            ->tty($tty)
            ->run(['container', 'exec', '-i', ...($tty ? ['-t'] : []), $container, 'bash'], $output)
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
    protected function runOrFail(array $command, string $message): ProcessResult
    {
        $result = Process::timeout(self::TIMEOUT)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                "{$message}: ".trim($result->errorOutput() ?: $result->output()),
            );
        }

        return $result;
    }
}
