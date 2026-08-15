<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\RebuildsInstanceContainers;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Console\Concerns\ResolvesPathRepositoryMounts;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\DependencyCaches;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\LifecycleHooks;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Nginx;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\PathRepositories;
use Zacksmash\Outpost\Processes;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\Supervisord;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

#[AsCommand(name: 'outpost:upgrade')]
class UpgradeCommand extends Command
{
    use RebuildsInstanceContainers;
    use ResolvesInstances;
    use ResolvesPathRepositoryMounts;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:upgrade
        {name? : The name of the instance}
        {--all : Upgrade every outdated or missing instance}
        {--mount-path-repos : Remount discovered composer path repositories without asking}';

    /**
     * The command description.
     */
    protected $description = 'Rebuild instance containers with the configured image while preserving worktrees';

    /**
     * Execute the console command.
     */
    public function handle(
        Outposts $outposts,
        RuntimeDriver $runtime,
        Doctor $doctor,
        Git $git,
        Host $host,
        DependencyCaches $dependencyCaches,
        PathRepositories $pathRepositories,
        Provisioner $provisioner,
        Nginx $nginx,
        Processes $processes,
        Supervisord $supervisord,
        LifecycleHooks $hooks,
    ): int {
        if ($this->option('all') && is_string($this->argument('name')) && $this->argument('name') !== '') {
            error('Choose an instance name or --all, not both.');

            return self::FAILURE;
        }

        $manifests = $this->manifests($outposts);

        if ($manifests === null) {
            return self::FAILURE;
        }

        try {
            ['image' => $image, 'digest' => $digest] = $this->configuredImage($runtime, $doctor);
            $states = $runtime->states();
            $targets = array_values(array_filter(
                $manifests,
                fn (Manifest $manifest): bool => ! isset($states[$manifest->container])
                    || $manifest->imageOutdated($image, $digest) !== false,
            ));

            if ($targets === []) {
                info($this->option('all')
                    ? 'Every instance already uses the configured image.'
                    : "The [{$manifests[0]->name}] instance already uses the configured image.");

                return self::SUCCESS;
            }

            if ($this->hasUnsafeWorktree($targets, $outposts, $git)) {
                return self::FAILURE;
            }

            foreach ($targets as $manifest) {
                $hooks->commands(LifecycleHooks::SETUP, $manifest->php);
            }

            $mounts = [];

            foreach ($targets as $manifest) {
                $this->assertRebuildable($manifest, $outposts);

                if ($this->option('all')) {
                    info("Reviewing path repositories for [{$manifest->name}].");
                }

                $mounts[$manifest->name] = $this->pathRepositoryMounts(
                    $pathRepositories,
                    $outposts->worktreePath($manifest->name),
                    $this->laravel->basePath(),
                    (bool) $this->option('mount-path-repos'),
                );
            }

            foreach ($targets as $manifest) {
                $state = $states[$manifest->container] ?? null;

                $this->rebuildInstanceContainer(
                    $manifest,
                    $outposts,
                    $runtime,
                    $git,
                    $host,
                    $dependencyCaches,
                    $provisioner,
                    $nginx,
                    $processes,
                    $supervisord,
                    $image,
                    $digest,
                    $mounts[$manifest->name],
                    $state,
                );

                info(($state === null ? 'Recreated' : 'Upgraded')." [{$manifest->name}] to [{$image}].");
                note("Kept: source worktree and branch [{$manifest->branch}].");
                note('Reset: container-local database and service data.');
            }
        } catch (RuntimeException $e) {
            error($e->getMessage());
            note('The source worktree and branch were left in place. Inspect any rebuilt container with [php artisan outpost:logs <name>].');

            return self::FAILURE;
        }

        outro(sprintf(
            'Finished upgrading %d instance%s.',
            count($targets),
            count($targets) === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * Resolve one selected manifest or every manifest.
     *
     * @return list<Manifest>|null
     */
    protected function manifests(Outposts $outposts): ?array
    {
        if (! $this->option('all')) {
            $manifest = $this->instance($outposts);

            return $manifest === null ? null : [$manifest];
        }

        $manifests = $outposts->all();

        if ($manifests === []) {
            error('No instances exist yet. Create one with [php artisan outpost].');

            return null;
        }

        return $manifests;
    }
}
