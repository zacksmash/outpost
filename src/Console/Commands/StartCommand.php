<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Console\Concerns\ResolvesPathRepositoryMounts;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\PathRepositories;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\Runtime;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class StartCommand extends Command
{
    use ResolvesInstances;
    use ResolvesPathRepositoryMounts;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:start
        {name? : The name of the instance}
        {--recreate : Recreate a missing container from the surviving instance files}
        {--mount-path-repos : Remount discovered composer path repositories without asking}';

    /**
     * The command description.
     */
    protected $description = 'Start an instance or recreate its missing container';

    /**
     * Execute the console command.
     */
    public function handle(
        Outposts $outposts,
        Runtime $runtime,
        Git $git,
        Host $host,
        PathRepositories $pathRepositories,
        Provisioner $provisioner,
    ): int {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        $recreating = false;

        try {
            $state = $runtime->state($manifest->container);

            if ($state === 'running') {
                info("The [{$manifest->name}] instance is already running: {$manifest->url}");

                return self::SUCCESS;
            }

            if ($state === null) {
                if (! $this->option('recreate')) {
                    error("The [{$manifest->name}] instance container is missing, but its worktree and manifest still exist.");
                    note("Recreate it without replacing the worktree:\n\n  php artisan outpost:start {$manifest->name} --recreate");

                    return self::FAILURE;
                }

                $recreating = true;
                $manifest = $manifest->withStatus('provisioning');
                $outposts->save($manifest);

                $this->recreate(
                    $manifest,
                    $outposts,
                    $runtime,
                    $git,
                    $host,
                    $pathRepositories,
                    $provisioner,
                );
            } else {
                spin(fn () => $runtime->start($manifest->container), "Starting [{$manifest->name}]");
            }

            $seconds = config()->integer('outpost.timeout');

            if (! spin(fn () => $runtime->awaitReady($manifest->container, $seconds, $manifest->secure()), 'Waiting for the instance to answer')) {
                error("The instance started but did not answer within {$seconds} seconds.");
                note("Check its logs with:\n\n  php artisan outpost:logs {$manifest->name}");

                if ($recreating) {
                    $outposts->save($manifest->withStatus('failed'));
                }

                return self::FAILURE;
            }

            if ($recreating) {
                $manifest = $manifest->withStatus('ready');
                $outposts->save($manifest);
            }

            $runtime->flushDnsCache();
        } catch (RuntimeException $e) {
            error($e->getMessage());

            if ($recreating) {
                $outposts->save($manifest->withStatus('failed'));
                note("The surviving worktree was left in place. If a container was created, inspect it with:\n\n  php artisan outpost:logs {$manifest->name}");
            }

            return self::FAILURE;
        }

        outro(($recreating ? 'Recreated' : 'Started').": {$manifest->url}");

        return self::SUCCESS;
    }

    /**
     * Boot and prepare a fresh container around the surviving instance files.
     */
    protected function recreate(
        Manifest $manifest,
        Outposts $outposts,
        Runtime $runtime,
        Git $git,
        Host $host,
        PathRepositories $pathRepositories,
        Provisioner $provisioner,
    ): void {
        $worktree = $outposts->worktreePath($manifest->name);
        $runtimePath = $outposts->runtimePath($manifest->name);

        if (! File::isDirectory($worktree) || ! File::exists($worktree.'/.env')) {
            throw new RuntimeException(
                "Unable to recreate [{$manifest->name}] because its worktree or environment file is missing.",
            );
        }

        foreach (['nginx.conf', 'supervisord.conf'] as $file) {
            if (! File::exists($runtimePath.'/'.$file)) {
                throw new RuntimeException(
                    "Unable to recreate [{$manifest->name}] because its generated [{$file}] file is missing.",
                );
            }
        }

        if ($manifest->secure()
            && (! File::exists($runtimePath.'/tls/certificate.pem')
                || ! File::exists($runtimePath.'/tls/key.pem'))) {
            throw new RuntimeException(
                "Unable to recreate [{$manifest->name}] because its HTTPS certificate files are missing.",
            );
        }

        $image = config()->string('outpost.image');
        $metadata = $runtime->imageMetadata($image);

        if ($metadata === null) {
            note("Pulling the missing [{$image}] image before recovery.");
            $runtime->pull(
                $image,
                fn (string $type, string $buffer) => $this->output->write($buffer),
            );
            $metadata = $runtime->imageMetadata($image);
        }

        if ($metadata === null
            || ($metadata['labels'][Runtime::IMAGE_RUNTIME_PATH_LABEL] ?? null) !== Runtime::IMAGE_RUNTIME_PATH) {
            throw new RuntimeException(
                "The [{$image}] image does not match this package's runtime-path contract. Run [php artisan outpost:doctor] for the exact repair.",
            );
        }

        $mounts = $this->pathRepositoryMounts(
            $pathRepositories,
            $worktree,
            $this->laravel->basePath(),
            (bool) $this->option('mount-path-repos'),
        );
        $gitDirectory = $git->commonDirectory();
        $resources = $runtime->validatedResources(
            $manifest->cpus ?? config()->integer('outpost.resources.cpus'),
            $manifest->memory ?? config()->string('outpost.resources.memory'),
        );

        warning('The missing container\'s writable service data cannot be recovered; the worktree and its files will be preserved.');

        spin(
            fn () => $runtime->boot(
                $manifest->container,
                $image,
                config()->string('outpost.dns'),
                [
                    $worktree.':/app',
                    $runtimePath.':/etc/outpost:ro',
                    "{$gitDirectory}:{$gitDirectory}:ro",
                    ...$mounts,
                ],
                cpus: $resources['cpus'],
                memory: $resources['memory'],
                uid: $host->userId(),
                gid: $host->groupId(),
            ),
            "Recreating [{$manifest->name}]",
        );

        $provisioner->recover(
            $manifest,
            onStep: fn (string $step) => info($step),
        );

        if ($manifest->processes !== []) {
            $runtime->releaseProcesses($manifest->container);
        }
    }
}
