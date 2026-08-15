<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Zacksmash\Outpost\Console\Concerns\RebuildsInstanceContainers;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Console\Concerns\ResolvesPathRepositoryMounts;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\Nginx;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\PathRepositories;
use Zacksmash\Outpost\Processes;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\Runtime;
use Zacksmash\Outpost\Supervisord;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;

class StartCommand extends Command
{
    use RebuildsInstanceContainers;
    use ResolvesInstances;
    use ResolvesPathRepositoryMounts;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:start
        {name? : The name of the instance}
        {--recreate : Deprecated; missing containers are recreated automatically}
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
        Doctor $doctor,
        Git $git,
        Host $host,
        PathRepositories $pathRepositories,
        Provisioner $provisioner,
        Nginx $nginx,
        Processes $processes,
        Supervisord $supervisord,
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
                $recreating = true;
                ['image' => $image, 'digest' => $digest] = $this->configuredImage($runtime, $doctor);
                $mounts = $this->pathRepositoryMounts(
                    $pathRepositories,
                    $outposts->worktreePath($manifest->name),
                    $this->laravel->basePath(),
                    (bool) $this->option('mount-path-repos'),
                );
                $manifest = $this->rebuildInstanceContainer(
                    $manifest,
                    $outposts,
                    $runtime,
                    $git,
                    $host,
                    $provisioner,
                    $nginx,
                    $processes,
                    $supervisord,
                    $image,
                    $digest,
                    $mounts,
                    null,
                );

                outro("Recreated: {$manifest->url}");

                return self::SUCCESS;
            } else {
                spin(fn () => $runtime->start($manifest->container), "Starting [{$manifest->name}]");
            }

            $seconds = config()->integer('outpost.timeout');

            if (! spin(fn () => $runtime->awaitReady($manifest->container, $seconds, $manifest->secure()), 'Waiting for the instance to answer')) {
                error("The instance started but did not answer within {$seconds} seconds.");
                note("Check its logs with:\n\n  php artisan outpost:logs {$manifest->name}");

                return self::FAILURE;
            }

            $runtime->flushDnsCache();
        } catch (RuntimeException $e) {
            error($e->getMessage());

            if ($recreating) {
                note("The surviving worktree was left in place. If a container was created, inspect it with:\n\n  php artisan outpost:logs {$manifest->name}");
            }

            return self::FAILURE;
        }

        outro("Started: {$manifest->url}");

        return self::SUCCESS;
    }
}
