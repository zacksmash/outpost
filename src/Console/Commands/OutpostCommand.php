<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Zacksmash\Outpost\Certificates;
use Zacksmash\Outpost\Detector;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Nginx;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\PathRepositories;
use Zacksmash\Outpost\Processes;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\Runtime;
use Zacksmash\Outpost\Supervisord;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class OutpostCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost
        {branch? : The branch the instance should run}
        {--name= : The name of the instance}
        {--pr= : The GitHub pull request number to run}
        {--remote=origin : The git remote used with --pr}
        {--open : Open the instance in the default browser when ready}
        {--seed : Seed the database after migrating}
        {--mount-path-repos : Mount discovered composer path repositories without asking}';

    /**
     * The command description.
     */
    protected $description = 'Create an isolated instance of the application';

    /**
     * Execute the console command.
     */
    public function handle(
        Git $git,
        Host $host,
        Doctor $doctor,
        Runtime $runtime,
        Certificates $certificates,
        Detector $detector,
        Outposts $outposts,
        PathRepositories $pathRepositories,
        Provisioner $provisioner,
        Processes $processes,
        Nginx $nginx,
        Supervisord $supervisord,
    ): int {
        $domain = config()->string('outpost.domain');
        $image = config()->string('outpost.image');

        $saved = null;

        try {
            if (! $git->hasCommits()) {
                error('Outpost needs a git repository with at least one commit to create instances from.');

                return self::FAILURE;
            }

            $pullRequest = $this->pullRequest();

            if ($pullRequest === false) {
                return self::FAILURE;
            }

            $argument = $this->argument('branch');

            if ($pullRequest !== null && is_string($argument) && $argument !== '') {
                error('Choose either a branch or --pr, not both.');

                return self::FAILURE;
            }

            $remote = $this->remote();

            if ($pullRequest !== null && $remote === '') {
                error('The --remote option must name a configured git remote.');

                return self::FAILURE;
            }

            $checks = $doctor->inspect();

            if ($doctor->requiresSetup($checks)) {
                if (! $this->input->isInteractive()) {
                    error('Outpost needs one-time machine setup before it can create an instance.');
                    note('Run [php artisan outpost:install --force]. Add [--https] when the non-interactive run may modify the system trust store.');

                    return self::FAILURE;
                }

                note('This Mac needs one-time Outpost setup before the first instance can be created.');

                if ($this->call('outpost:install') !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }

            $published = $runtime->publicationDomain();

            if ($published !== null && $published !== $domain) {
                error("Instance URLs would use the [{$domain}] domain, but this machine publishes container hostnames under [{$published}], so they would never resolve.");
                note("Either set OUTPOST_DOMAIN={$published} to match this machine, or change the machine's publication domain:\n\n  1. Edit ~/.config/container/config.toml so [dns] has domain = \"{$domain}\"\n  2. Restart the runtime: container system stop && container system start");

                return self::FAILURE;
            }

            if (! $runtime->domainRegistered($domain)) {
                error("The [{$domain}] domain is not registered with the container DNS resolver.");
                note("Run this once, then try again:\n\n  sudo container system dns create {$domain}");

                return self::FAILURE;
            }

            if (! $runtime->hasImage($image)) {
                error("The [{$image}] base image is not installed locally.");
                note("Install it once, then try again:\n\n  php artisan outpost:pull\n\nFor a customized local build, use [php artisan outpost:build].");

                return self::FAILURE;
            }

            $resources = $runtime->validatedResources(
                config('outpost.resources.cpus'),
                config('outpost.resources.memory'),
            );
            $uid = $host->userId();
            $gid = $host->groupId();

            $reference = $pullRequest === null
                ? $this->branch($git)
                : "outpost/pr-{$pullRequest}";
            $branch = $pullRequest === null
                ? $git->localBranchName($reference)
                : $reference;

            if ($git->branchCheckedOut($branch)) {
                error("The [{$branch}] branch is already checked out in another worktree.");

                return self::FAILURE;
            }

            $name = $this->name(
                $outposts,
                $pullRequest === null ? $branch : "pr-{$pullRequest}",
            );

            if (($invalid = $this->invalidName($outposts, $name)) !== null) {
                error($invalid);

                return self::FAILURE;
            }

            $container = $name.'-'.Str::slug(basename($this->laravel->basePath()));

            if ($runtime->exists($container)) {
                error("A container named [{$container}] already exists. Remove it before reusing the name.");

                return self::FAILURE;
            }

            $detection = $detector->detect();
            $secure = $certificates->enabled();
            $url = ($secure ? 'https' : 'http')."://{$container}.{$domain}";
            $commands = $processes->commands(
                php: $detection->php,
                server: $detection->server,
                frontend: $detection->frontend,
                url: $url,
                octaneServer: $detection->octaneServer,
            );

            info(sprintf(
                'PHP %s (%s) · Resources: %d CPU / %s · Frontend: %s · Services: %s · Processes: %s',
                $detection->php,
                $detection->server === 'octane'
                    ? 'Octane / '.$detection->octaneServer
                    : 'PHP-FPM',
                $resources['cpus'],
                $resources['memory'],
                $detection->frontend === 'vite' ? 'Vite' : ucfirst($detection->frontend),
                $detection->services === [] ? 'none' : implode(', ', $detection->services),
                $commands === [] ? 'none' : implode(', ', array_keys($commands)),
            ));

            if ($detection->deferred !== []) {
                warning('Detected but not run inside instances: '.implode(', ', $detection->deferred).'.');
            }

            $manifest = new Manifest(
                name: $name,
                container: $container,
                url: $url,
                branch: $branch,
                php: $detection->php,
                server: $detection->server,
                frontend: $detection->frontend,
                exposeServices: config()->boolean('outpost.expose_services'),
                services: $detection->services,
                deferred: $detection->deferred,
                processes: array_keys($commands),
                database: $detection->database,
                createdAt: CarbonImmutable::now(),
                cpus: $resources['cpus'],
                memory: $resources['memory'],
                octaneServer: $detection->octaneServer,
                status: 'provisioning',
            );

            $outposts->save($manifest);
            $saved = $manifest;

            spin(
                fn () => $pullRequest === null
                    ? $git->addWorktree($outposts->worktreePath($name), $reference)
                    : $git->addPullRequestWorktree(
                        $outposts->worktreePath($name),
                        $pullRequest,
                        $remote,
                    ),
                $pullRequest === null
                    ? "Checking out the [{$reference}] branch"
                    : "Fetching GitHub pull request #{$pullRequest}",
            );

            $mounts = $this->mounts(
                $pathRepositories,
                $outposts->worktreePath($name),
                $this->laravel->basePath(),
            );
            $gitDirectory = $git->commonDirectory();

            $outposts->writeRuntime($name, [
                'nginx.conf' => $nginx->generate($manifest),
                'supervisord.conf' => $supervisord->generate($manifest, $commands),
            ]);

            $tlsDirectory = $outposts->runtimePath($name).'/tls';

            if ($secure) {
                spin(
                    fn () => $certificates->createForHost("{$container}.{$domain}", $tlsDirectory),
                    'Creating the HTTPS certificate',
                );
            }

            $this->ensureInstancesIgnored();

            spin(
                fn () => $runtime->boot(
                    $container,
                    $image,
                    config()->string('outpost.dns'),
                    [
                        $outposts->worktreePath($name).':/app',
                        $outposts->runtimePath($name).':/etc/outpost:ro',
                        "{$gitDirectory}:{$gitDirectory}:ro",
                        ...$mounts,
                    ],
                    cpus: $resources['cpus'],
                    memory: $resources['memory'],
                    uid: $uid,
                    gid: $gid,
                ),
                'Booting the instance',
            );

            $provisioner->provision(
                $manifest,
                seed: (bool) $this->option('seed'),
                onStep: fn (string $step) => info($step),
            );

            if ($commands !== []) {
                $runtime->releaseProcesses($container);
            }

            $seconds = config()->integer('outpost.timeout');

            if (! spin(fn () => $runtime->awaitReady($container, $seconds, $secure), 'Checking the application response')) {
                throw new RuntimeException(
                    "The application did not answer HTTP within {$seconds} seconds. Check its logs with [php artisan outpost:logs {$name}].",
                );
            }

            $manifest = $manifest->withStatus('ready');
            $outposts->save($manifest);

            $runtime->flushDnsCache();
        } catch (RuntimeException $e) {
            error($e->getMessage());

            if ($saved !== null) {
                $saved = $saved->withStatus('failed');
                $outposts->save($saved);
                note("Everything created so far was left in place for debugging.\nRemove the instance with:\n\n  php artisan outpost:remove {$saved->name}");
            }

            return self::FAILURE;
        }

        if ($this->option('open')) {
            try {
                $host->open($manifest->url);
            } catch (RuntimeException $e) {
                warning($e->getMessage());
                note("Open it manually: {$manifest->url}");
            }
        }

        if ($manifest->services !== []) {
            note("Inspect service URLs and credentials with:\n\n  php artisan outpost:info {$manifest->name}");
        }

        outro("The instance is ready: {$manifest->url}");

        return self::SUCCESS;
    }

    /**
     * Determine the branch the instance should run.
     */
    protected function branch(Git $git): string
    {
        $branch = $this->argument('branch');

        if (is_string($branch) && $branch !== '') {
            return $branch;
        }

        return suggest(
            label: 'Which branch should the instance run?',
            options: array_values(array_diff($git->branchSuggestions(), $git->checkedOutBranches())),
            required: true,
            hint: 'Pick a local or remote branch, or type a new local branch name.',
        );
    }

    /**
     * Get and validate the requested pull request number.
     */
    protected function pullRequest(): int|false|null
    {
        $pullRequest = $this->option('pr');

        if ($pullRequest === null) {
            return null;
        }

        if (! is_string($pullRequest)
            || ! ctype_digit($pullRequest)
            || (int) $pullRequest < 1) {
            error('The pull request number must be a positive whole number.');

            return false;
        }

        return (int) $pullRequest;
    }

    /**
     * Get the git remote used to fetch a pull request.
     */
    protected function remote(): string
    {
        $remote = $this->option('remote');

        return is_string($remote) ? $remote : 'origin';
    }

    /**
     * Determine the name of the instance.
     */
    protected function name(Outposts $outposts, string $branch): string
    {
        $name = $this->option('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return text(
            label: 'What should the instance be named?',
            default: Str::slug($branch),
            required: true,
            validate: fn (string $value) => $this->invalidName($outposts, $value),
        );
    }

    /**
     * Determine why the given instance name is unacceptable, if it is.
     */
    protected function invalidName(Outposts $outposts, string $name): ?string
    {
        if ($name === '' || Str::slug($name) !== $name) {
            return 'The name must be a URL-friendly slug.';
        }

        if ($outposts->exists($name)) {
            return "The [{$name}] instance already exists. Remove it with [php artisan outpost:remove {$name}].";
        }

        return null;
    }

    /**
     * Resolve which composer path repositories should be mounted, if any.
     *
     * The repository list comes from a writable file inside the worktree,
     * so nothing is mounted without a human saying yes — and a scripted
     * run without the explicit flag mounts nothing at all.
     *
     * @return list<string>
     */
    protected function mounts(PathRepositories $pathRepositories, string $worktree, string $project): array
    {
        $scan = $pathRepositories->scan($worktree, $project);

        foreach ($scan->warnings as $warning) {
            warning($warning);
        }

        if (! $scan->any()) {
            return [];
        }

        warning('This application uses composer path repositories outside the worktree:');
        note(implode("\n", $scan->paths));

        if ($this->option('mount-path-repos')
            || confirm('Mount these path repositories read-only into the instance?', false)) {
            $scan->createHostBridges(dirname($worktree));

            return $scan->mounts();
        }

        warning('Mounting nothing. Provisioning may fail; re-run with --mount-path-repos to mount them.');

        return [];
    }

    /**
     * Keep the instances directory out of version control.
     *
     * Runs after the worktree exists, so a failed checkout never leaves
     * a stray .gitignore edit behind.
     */
    protected function ensureInstancesIgnored(): void
    {
        $path = config()->string('outpost.path');

        if (str_starts_with($path, '/')) {
            return;
        }

        $gitignore = $this->laravel->basePath('.gitignore');
        $line = '/'.trim($path, '/');

        $contents = File::exists($gitignore) ? File::get($gitignore) : '';

        if (in_array($line, array_map('trim', explode("\n", $contents)), true)) {
            return;
        }

        $contents = $contents === '' ? "{$line}\n" : rtrim($contents)."\n{$line}\n";

        if (File::put($gitignore, $contents) === false) {
            throw new RuntimeException('Unable to add the instances directory to .gitignore.');
        }
    }
}
