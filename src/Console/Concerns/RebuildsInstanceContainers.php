<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Concerns;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\DependencyCaches;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Nginx;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Processes;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\Supervisord;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

trait RebuildsInstanceContainers
{
    use FlushesDnsCaches;

    /**
     * Ensure the configured image is installed, compatible, and identifiable.
     *
     * @return array{image: string, digest: string}
     */
    protected function configuredImage(RuntimeDriver $runtime, Doctor $doctor): array
    {
        $image = config()->string('outpost.image');
        $metadata = $runtime->imageMetadata($image);

        if ($metadata === null) {
            note("Pulling the missing [{$image}] image before rebuilding instances.");

            try {
                $runtime->pull(
                    $image,
                    fn (string $type, string $buffer) => $this->output->write($buffer),
                );
            } catch (RuntimeException $e) {
                throw new RuntimeException($e->getMessage()."\n".$doctor->imageRemedy($image), previous: $e);
            }

            $metadata = $runtime->imageMetadata($image);
        }

        if ($metadata === null) {
            throw new RuntimeException(
                "The [{$image}] image is still missing. ".$doctor->imageRemedy($image),
            );
        }

        if (($problem = $doctor->imageProblem($image, $metadata)) !== null) {
            throw new RuntimeException($problem.' '.$doctor->imageRemedy($image, force: true));
        }

        /** @var string $digest */
        $digest = $metadata['digest'];

        return ['image' => $image, 'digest' => $digest];
    }

    /**
     * Refuse to rebuild over uncommitted work before deleting anything.
     *
     * Rebuilding rewrites the worktree's .env and reruns dependency and
     * asset builds in place, so dirty worktrees are always refused.
     *
     * @param  list<Manifest>  $manifests
     */
    protected function hasUnsafeWorktree(array $manifests, Outposts $outposts, Git $git): bool
    {
        $dirty = false;

        foreach ($manifests as $manifest) {
            $this->assertWorktreeRebuildable($manifest, $outposts);
            $status = $git->worktreeStatus($outposts->worktreePath($manifest->name));

            if ($status === '') {
                continue;
            }

            error("The [{$manifest->name}] worktree has uncommitted changes, so its container was not rebuilt.");

            foreach (explode("\n", $status) as $file) {
                $this->line($file);
            }

            note('Commit or preserve these files first. Outpost will not discard them.');
            $dirty = true;
        }

        return $dirty;
    }

    /**
     * Validate the host-side files needed to rebuild an instance container.
     */
    protected function assertRebuildable(Manifest $manifest, Outposts $outposts): void
    {
        $this->assertWorktreeRebuildable($manifest, $outposts);

        $runtimePath = $outposts->runtimePath($manifest->name);

        if ($manifest->secure()
            && (! File::exists($runtimePath.'/tls/certificate.pem')
                || ! File::exists($runtimePath.'/tls/key.pem'))) {
            throw new RuntimeException(
                "Unable to rebuild [{$manifest->name}] because its HTTPS certificate files are missing.",
            );
        }
    }

    /**
     * Validate the surviving source files before performing any maintenance.
     */
    protected function assertWorktreeRebuildable(Manifest $manifest, Outposts $outposts): void
    {
        $worktree = $outposts->worktreePath($manifest->name);

        if (! File::isDirectory($worktree) || ! File::exists($worktree.'/.env')) {
            throw new RuntimeException(
                "Unable to rebuild [{$manifest->name}] because its worktree or environment file is missing.",
            );
        }
    }

    /**
     * Replace or recreate a disposable container around a surviving worktree.
     *
     * @param  list<string>  $mounts
     */
    protected function rebuildInstanceContainer(
        Manifest $manifest,
        Outposts $outposts,
        RuntimeDriver $runtime,
        Git $git,
        Host $host,
        DependencyCaches $dependencyCaches,
        Provisioner $provisioner,
        Nginx $nginx,
        Processes $processes,
        Supervisord $supervisord,
        string $image,
        string $digest,
        array $mounts,
        ?string $existingState,
        ?Manifest $previousManifest = null,
    ): Manifest {
        $this->assertRebuildable($manifest, $outposts);

        $previousManifest ??= $manifest;

        $worktree = $outposts->worktreePath($manifest->name);
        $runtimePath = $outposts->runtimePath($manifest->name);
        $dependencyCacheMounts = $dependencyCaches->mounts();
        $gitDirectory = $git->commonDirectory();
        $resources = $runtime->validatedResources(
            $manifest->cpus ?? config()->integer('outpost.resources.cpus'),
            $manifest->memory ?? config()->string('outpost.resources.memory'),
        );
        $commands = $processes->commands($manifest->php);
        $rebuilt = $manifest
            ->withProcesses(array_keys($commands))
            ->withPathRepositoryMounts($mounts);
        $nginxConfig = $nginx->generate($rebuilt);
        $supervisorConfig = $supervisord->generate($rebuilt, $commands);
        $manifest = $manifest->withStatus('provisioning');
        $outposts->save($manifest);

        try {
            if ($existingState !== null) {
                $force = false;

                if ($existingState === 'running') {
                    try {
                        spin(fn () => $runtime->stop($manifest->container), "Stopping [{$manifest->name}]");
                    } catch (RuntimeException $e) {
                        warning($e->getMessage());
                        $force = true;
                    }
                }

                spin(
                    fn () => $runtime->delete($manifest->container, $force),
                    "Deleting the old [{$manifest->name}] container",
                );
            }

            File::delete($runtimePath.'/octane-watch');
            $outposts->writeRuntime($manifest->name, [
                'nginx.conf' => $nginxConfig,
                'supervisord.conf' => $supervisorConfig,
            ]);
            $manifest = $rebuilt
                ->withStatus('provisioning')
                ->withImage($image, $digest);
            $outposts->save($manifest);

            warning("Rebuilding [{$manifest->name}] resets its container-local database and service data.");
            note("The worktree and branch [{$manifest->branch}] are preserved.");

            spin(
                fn () => $runtime->boot(
                    $manifest->container,
                    $image,
                    config()->string('outpost.dns'),
                    [
                        $worktree.':/app',
                        $runtimePath.':/etc/outpost:ro',
                        "{$gitDirectory}:{$gitDirectory}:ro",
                        ...$dependencyCacheMounts,
                        ...$mounts,
                    ],
                    cpus: $resources['cpus'],
                    memory: $resources['memory'],
                    uid: $host->userId(),
                    gid: $host->groupId(),
                    environment: $dependencyCaches->environment(),
                ),
                "Rebuilding [{$manifest->name}]",
            );

            $provisioner->recover(
                $manifest,
                onStep: fn (string $step) => info($step),
                previous: $previousManifest,
            );

            if ($manifest->processes !== []) {
                $runtime->releaseProcesses($manifest->container);
            }

            $seconds = config()->integer('outpost.timeout');

            if (! spin(
                fn () => $runtime->awaitReady($manifest->container, $seconds, $manifest->secure()),
                'Waiting for the rebuilt instance to answer',
            )) {
                throw new RuntimeException(
                    "The rebuilt instance did not answer within {$seconds} seconds. Check its logs with [php artisan outpost:logs {$manifest->name}].",
                );
            }

            $manifest = $manifest->withStatus('ready');
            $outposts->save($manifest);
            $this->flushDnsCacheQuietly($runtime);
        } catch (RuntimeException $e) {
            $outposts->save($manifest->withStatus('failed'));

            throw $e;
        }

        return $manifest;
    }
}
