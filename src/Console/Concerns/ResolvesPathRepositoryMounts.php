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
     * @param  list<string>  $previouslyApproved
     * @return list<string>
     */
    protected function pathRepositoryMounts(
        PathRepositories $pathRepositories,
        string $worktree,
        string $project,
        bool $approved,
        array $previouslyApproved = [],
    ): array {
        $scan = $pathRepositories->scan($worktree, $project);

        foreach ($scan->warnings as $warning) {
            warning($warning);
        }

        if (! $scan->any()) {
            return [];
        }

        $discovered = $scan->mounts();
        $previouslyApproved = array_values(array_unique([
            ...$previouslyApproved,
            ...$scan->bridgedMounts(dirname($worktree)),
        ]));
        $reused = array_values(array_intersect($discovered, $previouslyApproved));
        $new = array_values(array_diff($discovered, $reused));

        if ($reused !== []) {
            note(sprintf(
                'Reusing %d previously approved path repository mount%s.',
                count($reused),
                count($reused) === 1 ? '' : 's',
            ));
        }

        if ($new === []) {
            $scan->createHostBridges(dirname($worktree), $reused);

            return $reused;
        }

        $newPaths = [];

        foreach ($discovered as $index => $mount) {
            if (in_array($mount, $new, true)) {
                $newPaths[] = $scan->paths[$index];
            }
        }

        warning($reused === []
            ? 'This application uses Composer path repositories outside the worktree:'
            : 'This application has newly discovered Composer path repositories outside the worktree:');
        note(implode("\n", $newPaths));

        $question = $reused === []
            ? 'Mount these path repositories read-only into the instance?'
            : sprintf(
                'Mount %s newly discovered path repositor%s read-only into the instance?',
                count($new) === 1 ? 'this' : 'these',
                count($new) === 1 ? 'y' : 'ies',
            );

        if ($approved || confirm($question, false)) {
            $scan->createHostBridges(dirname($worktree), $discovered);

            return $discovered;
        }

        $scan->createHostBridges(dirname($worktree), $reused);

        if ($reused !== []) {
            warning(sprintf(
                'Keeping %d previously approved mount%s. Newly discovered repositories were not mounted; re-run with --mount-path-repos to approve them.',
                count($reused),
                count($reused) === 1 ? '' : 's',
            ));

            return $reused;
        }

        warning('Mounting nothing. Provisioning may fail; re-run with --mount-path-repos to mount them.');

        return [];
    }
}
