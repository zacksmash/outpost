<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Concerns;

use Zacksmash\Outpost\PathRepositories;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

trait ResolvesPathRepositoryMounts
{
    /**
     * Resolve explicitly approved Composer path repository mounts.
     *
     * @return list<string>
     */
    protected function pathRepositoryMounts(
        PathRepositories $pathRepositories,
        string $worktree,
        string $project,
        bool $approved,
    ): array {
        $scan = $pathRepositories->scan($worktree, $project);

        foreach ($scan->warnings as $warning) {
            warning($warning);
        }

        if (! $scan->any()) {
            return [];
        }

        warning('This application uses Composer path repositories outside the worktree:');
        note(implode("\n", $scan->paths));

        if ($approved || confirm('Mount these path repositories read-only into the instance?', false)) {
            $scan->createHostBridges(dirname($worktree));

            return $scan->mounts();
        }

        warning('Mounting nothing. Provisioning may fail; re-run with --mount-path-repos to mount them.');

        return [];
    }
}
