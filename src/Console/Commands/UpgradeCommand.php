<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Certificates;
use Zacksmash\Outpost\Console\Concerns\RebuildsInstanceContainers;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Console\Concerns\ResolvesPathRepositoryMounts;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\DatabaseServices;
use Zacksmash\Outpost\DependencyCaches;
use Zacksmash\Outpost\Detector;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\LegacyRedisDump;
use Zacksmash\Outpost\LifecycleHooks;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Nginx;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\PathRepositories;
use Zacksmash\Outpost\Processes;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\Secrets;
use Zacksmash\Outpost\Supervisord;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;

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
        {--all : Upgrade all outdated or missing instances}
        {--force : Rebuild from the current Outpost configuration even when the image is current}
        {--mount-path-repos : Approve newly discovered Composer path repositories without prompting}';

    /**
     * The command description.
     */
    protected $description = 'Rebuild instance containers with the configured image';

    /**
     * Execute the console command.
     */
    public function handle(
        Outposts $outposts,
        RuntimeDriver $runtime,
        Doctor $doctor,
        Detector $detector,
        Certificates $certificates,
        Git $git,
        Host $host,
        DependencyCaches $dependencyCaches,
        Secrets $secrets,
        PathRepositories $pathRepositories,
        Provisioner $provisioner,
        Nginx $nginx,
        Processes $processes,
        Supervisord $supervisord,
        LifecycleHooks $hooks,
        LegacyRedisDump $legacyRedisDump,
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
            $force = (bool) $this->option('force');
            $targets = array_values(array_filter(
                $manifests,
                fn (Manifest $manifest): bool => $force
                    || ! isset($states[$manifest->container])
                    || $manifest->imageOutdated($image, $digest) !== false
                    || DatabaseServices::reconcile($manifest->database, $manifest->services) !== $manifest->database,
            ));

            if ($targets === []) {
                info($this->option('all')
                    ? 'Every instance already uses the configured image.'
                    : "The [{$manifests[0]->name}] instance already uses the configured image.");

                return self::SUCCESS;
            }

            // Fail fast before any worktree mutation or certificate work when a
            // declared secret is unset. Secrets are project-global, so one check
            // covers every target and avoids a redundant per-instance read.
            $secrets->assertAllStored();

            if ($this->hasUnsafeWorktree($targets, $outposts, $git, $legacyRedisDump)) {
                return self::FAILURE;
            }

            $previousManifests = [];

            foreach ($targets as $manifest) {
                $previousManifests[$manifest->name] = $manifest;
            }

            if ($force) {
                $detection = $detector->detect();
                $secure = $certificates->enabled();
                $domain = config()->string('outpost.domain');
                $resources = $runtime->validatedResources(
                    config('outpost.resources.cpus'),
                    config('outpost.resources.memory'),
                );

                $targets = array_map(
                    fn (Manifest $manifest): Manifest => $manifest->withConfiguration(
                        $detection,
                        ($secure ? 'https' : 'http')."://{$manifest->container}.{$domain}",
                        config()->boolean('outpost.expose_services'),
                        $resources['cpus'],
                        $resources['memory'],
                    ),
                    $targets,
                );

                if ($secure) {
                    foreach ($targets as $manifest) {
                        spin(
                            fn () => $certificates->createForHost(
                                "{$manifest->container}.{$domain}",
                                $outposts->runtimePath($manifest->name).'/tls',
                            ),
                            "Creating the HTTPS certificate for [{$manifest->name}]",
                        );
                    }
                }
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
                    $manifest->pathRepositoryMounts,
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
                    $secrets,
                    $provisioner,
                    $nginx,
                    $processes,
                    $supervisord,
                    $legacyRedisDump,
                    $image,
                    $digest,
                    $mounts[$manifest->name],
                    $state,
                    $previousManifests[$manifest->name],
                );

                info($force
                    ? "Rebuilt [{$manifest->name}] from the current Outpost configuration using [{$image}]."
                    : ($state === null ? 'Recreated' : 'Upgraded')." [{$manifest->name}] to [{$image}].");
            }
        } catch (RuntimeException $e) {
            error($e->getMessage());
            note('The source worktree and branch were left in place. Inspect any rebuilt container with [php artisan outpost:logs <name>].');

            return self::FAILURE;
        }

        outro(sprintf(
            $force ? 'Finished rebuilding %d instance%s.' : 'Finished upgrading %d instance%s.',
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
