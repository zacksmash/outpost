<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\FlushesDnsCaches;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\LegacyRedisDump;
use Zacksmash\Outpost\LifecycleHooks;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

#[AsCommand(name: 'outpost:remove')]
class RemoveCommand extends Command
{
    use FlushesDnsCaches;
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:remove
        {name? : The name of the instance}
        {--force : Skip confirmation without bypassing worktree protection}
        {--delete-branch : Delete the instance branch after removal without prompting}
        {--discard-changes : Remove even when the worktree has uncommitted changes}
        {--forget : Remove local state without contacting the runtime or running teardown hooks}';

    /**
     * The command description.
     */
    protected $description = 'Remove an instance and its container, worktree, and data';

    /**
     * Execute the console command.
     */
    public function handle(
        Outposts $outposts,
        RuntimeDriver $runtime,
        Git $git,
        LifecycleHooks $hooks,
        LegacyRedisDump $legacyRedisDump,
    ): int {
        if (($name = $this->instanceName($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            $manifest = $outposts->exists($name) ? $outposts->find($name) : null;
        } catch (InvalidArgumentException $e) {
            // An invalid name can never own an instance directory, so there
            // is nothing on disk the broken-manifest path could clean up.
            error($e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            return $this->removeBroken($outposts, $git, $name, $e);
        }

        if ($manifest === null) {
            error("The [{$name}] instance does not exist. See [php artisan outpost:list].");

            return self::FAILURE;
        }

        if ($this->refusesDirtyWorktree($outposts, $git, $manifest->name, $manifest, $legacyRedisDump)) {
            return self::FAILURE;
        }

        $forget = (bool) $this->option('forget');
        $confirmation = $forget
            ? "Forget the [{$manifest->name}] instance? Its worktree and data will be destroyed, but container [{$manifest->container}] will be left behind."
            : "Remove the [{$manifest->name}] instance? Its container, worktree, and data will be destroyed.";

        if (! $this->option('force')) {
            if (($refused = $this->refusesWithoutTerminal($manifest->name)) !== null) {
                return $refused;
            }

            if (! confirm($confirmation, false)) {
                info('Nothing removed.');

                return self::SUCCESS;
            }
        }

        try {
            if ($forget) {
                warning("Forgetting [{$manifest->name}] without contacting the runtime.");
                note('Teardown hooks will not run.');
                note("Container [{$manifest->container}] may remain.");
                note("Remove it later with [container delete --force {$manifest->container}].");
            } else {
                $runtimeState = $runtime->state($manifest->container);

                if ($runtimeState === 'running' && $manifest->status === 'ready') {
                    if ($hooks->commands(LifecycleHooks::TEARDOWN, $manifest->php) !== []) {
                        $hooks->run(
                            LifecycleHooks::TEARDOWN,
                            $manifest,
                            fn (string $step) => info($step),
                        );
                    }
                } elseif ($hooks->configured(LifecycleHooks::TEARDOWN)) {
                    $reason = $runtimeState !== 'running'
                        ? 'container state is ['.($runtimeState ?? 'missing').']'
                        : "provisioning status is [{$manifest->status}]";
                    warning("Skipped configured teardown hooks because {$reason}.");
                }

                $this->removeContainer($runtime, $manifest, $runtimeState);
            }

            $this->removeWorktree($outposts, $git, $manifest->name);

            $outposts->delete($manifest->name);

            $this->flushDnsCacheQuietly($runtime);
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        $this->offerBranchDeletion($git, $manifest->branch);

        outro("Removed [{$manifest->name}].");

        return self::SUCCESS;
    }

    /**
     * Stop and delete the instance's container if it still exists.
     *
     * A failed stop is tolerated — the intent of remove is "make this go
     * away" — but the container is then deleted by force.
     */
    protected function removeContainer(RuntimeDriver $runtime, Manifest $manifest, ?string $state): void
    {
        if ($state === null) {
            return;
        }

        $force = false;

        if ($state === 'running') {
            try {
                $runtime->stop($manifest->container);
            } catch (RuntimeException $e) {
                warning($e->getMessage());

                $force = true;
            }
        }

        spin(fn () => $runtime->delete($manifest->container, $force), 'Deleting the container');
    }

    /**
     * Remove the instance's worktree, or prune a stale registration.
     */
    protected function removeWorktree(Outposts $outposts, Git $git, string $name): void
    {
        if (File::isDirectory($worktree = $outposts->worktreePath($name))) {
            spin(fn () => $git->removeWorktree($worktree), 'Removing the worktree');

            return;
        }

        // The directory is gone but git may still record it, which keeps
        // the branch "checked out" and blocks its next instance.
        $git->pruneWorktrees();
    }

    /**
     * Remove an instance whose manifest can no longer be read.
     */
    protected function removeBroken(Outposts $outposts, Git $git, string $name, RuntimeException $reason): int
    {
        warning($reason->getMessage());

        if (! File::isDirectory($outposts->path($name))) {
            error("The [{$name}] instance does not exist. See [php artisan outpost:list].");

            return self::FAILURE;
        }

        if ($this->refusesDirtyWorktree($outposts, $git, $name)) {
            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (($refused = $this->refusesWithoutTerminal($name)) !== null) {
                return $refused;
            }

            if (! confirm("The [{$name}] manifest is unreadable, so its container cannot be determined. Remove the instance directory anyway?", false)) {
                info('Nothing removed.');

                return self::SUCCESS;
            }
        }

        try {
            $this->removeWorktree($outposts, $git, $name);

            $outposts->delete($name);
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        warning('If the instance still has a container, delete it manually with [container delete <name>].');

        outro("Removed [{$name}].");

        return self::SUCCESS;
    }

    /**
     * Refuse to destroy uncommitted work without an explicit discard option.
     */
    protected function refusesDirtyWorktree(
        Outposts $outposts,
        Git $git,
        string $name,
        ?Manifest $manifest = null,
        ?LegacyRedisDump $legacyRedisDump = null,
    ): bool {
        $worktree = $outposts->worktreePath($name);

        if ((bool) $this->option('discard-changes') || ! File::isDirectory($worktree)) {
            return false;
        }

        try {
            $status = $git->worktreeStatus($worktree);

            if ($manifest !== null && $legacyRedisDump?->remove(
                $manifest,
                $worktree,
                $outposts->runtimePath($name),
                $status,
            )) {
                info("Removed legacy Redis data file [dump.rdb] from [{$name}].");
                $status = $git->worktreeStatus($worktree);
            }
        } catch (RuntimeException $e) {
            error($e->getMessage());
            note("Unable to check the [{$name}] worktree for uncommitted changes, so it was not removed.\nRemove it anyway, discarding anything uncommitted, with:\n\n  php artisan outpost:remove {$name} --discard-changes");

            return true;
        }

        if ($status === '') {
            return false;
        }

        error("The [{$name}] worktree has uncommitted changes, so it was not removed.");
        note($status);
        note("Commit or preserve the changes first, or explicitly discard them with:\n\n  php artisan outpost:remove {$name} --discard-changes");

        return true;
    }

    /**
     * Refuse to fall back to a declined confirmation without a terminal.
     *
     * Without a terminal there is nobody to approve the removal, so the
     * confirmation prompt would silently take its "no" default while the
     * command still exits successfully. Consent must arrive as the
     * explicit --force option instead.
     */
    protected function refusesWithoutTerminal(string $name): ?int
    {
        if ($this->input->isInteractive()) {
            return null;
        }

        error('Refusing to remove non-interactively without --force.');
        note("Approve the removal explicitly:\n\n  php artisan outpost:remove {$name} --force");

        return self::FAILURE;
    }

    /**
     * Delete the instance's branch when requested, or offer to when it is safe.
     *
     * The instance is already gone by the time an explicitly requested
     * deletion turns out to be unsafe, so that is reported as a loud
     * warning rather than a failure.
     */
    protected function offerBranchDeletion(Git $git, string $branch): void
    {
        $requested = (bool) $this->option('delete-branch');

        try {
            if (! $git->branchExists($branch)) {
                return;
            }

            if ($git->branchCheckedOut($branch)) {
                if ($requested) {
                    warning("The [{$branch}] branch was not deleted because it is checked out in another worktree.");
                }

                return;
            }

            if (! $requested
                && ($this->option('force') || ! confirm("Delete the [{$branch}] branch too?", false))) {
                return;
            }

            $git->deleteBranch($branch);

            info("Deleted the [{$branch}] branch.");
        } catch (RuntimeException $e) {
            warning($e->getMessage());
        }
    }
}
