<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class RemoveCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:remove
        {name? : The name of the instance}
        {--force : Remove without asking}
        {--discard-changes : Explicitly remove a worktree with uncommitted changes}
        {--forget : Remove local state without contacting the container runtime}';

    /**
     * The command description.
     */
    protected $description = 'Remove an instance entirely: container, worktree, and data';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime, Git $git): int
    {
        if (($name = $this->instanceName($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            $manifest = $outposts->exists($name) ? $outposts->find($name) : null;
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->removeBroken($outposts, $git, $name, $e);
        }

        if ($manifest === null) {
            error("The [{$name}] instance does not exist. See [php artisan outpost:list].");

            return self::FAILURE;
        }

        if ($this->refusesDirtyWorktree($outposts, $git, $manifest->name)) {
            return self::FAILURE;
        }

        $forget = (bool) $this->option('forget');
        $confirmation = $forget
            ? "Forget the [{$manifest->name}] instance? Its worktree and data will be destroyed, but container [{$manifest->container}] will be left behind."
            : "Remove the [{$manifest->name}] instance? Its container, worktree, and data will be destroyed.";

        if (! $this->option('force') && ! confirm($confirmation, false)) {
            info('Nothing removed.');

            return self::SUCCESS;
        }

        try {
            if ($forget) {
                warning("Skipped container [{$manifest->container}].");
                warning("Remove it later with [container delete --force {$manifest->container}].");
            } else {
                $this->removeContainer($runtime, $manifest);
            }

            $this->removeWorktree($outposts, $git, $manifest->name);

            $outposts->delete($manifest->name);

            $runtime->flushDnsCache();
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
    protected function removeContainer(RuntimeDriver $runtime, Manifest $manifest): void
    {
        if (! $runtime->exists($manifest->container)) {
            return;
        }

        $force = false;

        try {
            $runtime->stop($manifest->container);
        } catch (RuntimeException $e) {
            warning($e->getMessage());

            $force = true;
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
    protected function removeBroken(Outposts $outposts, Git $git, string $name, RuntimeException|InvalidArgumentException $reason): int
    {
        warning($reason->getMessage());

        if (! File::isDirectory($outposts->path($name))) {
            error("The [{$name}] instance does not exist. See [php artisan outpost:list].");

            return self::FAILURE;
        }

        if ($this->refusesDirtyWorktree($outposts, $git, $name)) {
            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! confirm("The [{$name}] manifest is unreadable, so its container cannot be determined. Remove the instance directory anyway?", false)) {
            info('Nothing removed.');

            return self::SUCCESS;
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
    protected function refusesDirtyWorktree(Outposts $outposts, Git $git, string $name): bool
    {
        $worktree = $outposts->worktreePath($name);

        if ((bool) $this->option('discard-changes') || ! File::isDirectory($worktree)) {
            return false;
        }

        $status = $git->worktreeStatus($worktree);

        if ($status === '') {
            return false;
        }

        error("The [{$name}] worktree has uncommitted changes, so it was not removed.");
        note($status);
        note("Commit or preserve the changes first, or explicitly discard them with:\n\n  php artisan outpost:remove {$name} --discard-changes");

        return true;
    }

    /**
     * Offer to delete the instance's branch when it is safe to do so.
     */
    protected function offerBranchDeletion(Git $git, string $branch): void
    {
        try {
            if ($this->option('force')
                || ! $git->branchExists($branch)
                || $git->branchCheckedOut($branch)
                || ! confirm("Delete the [{$branch}] branch too?", false)) {
                return;
            }

            $git->deleteBranch($branch);

            info("Deleted the [{$branch}] branch.");
        } catch (RuntimeException $e) {
            warning($e->getMessage());
        }
    }
}
