<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\Facades\File;

class LegacyRedisDump
{
    /**
     * Remove an untracked Redis snapshot proven to have leaked from legacy runtime configuration.
     */
    public function remove(Manifest $manifest, string $worktree, string $runtimePath, string $status): bool
    {
        $dump = $worktree.'/dump.rdb';
        $supervisor = $runtimePath.'/supervisord.conf';

        if (! $manifest->uses('redis')
            || ! $this->isUntracked($status)
            || is_link($dump)
            || ! File::isFile($dump)
            || ! File::isFile($supervisor)) {
            return false;
        }

        $configuration = File::get($supervisor);

        if (! $this->usesApplicationWorkingDirectory($configuration)
            || ! $this->hasRedisHeader($dump)) {
            return false;
        }

        return File::delete($dump);
    }

    /**
     * Determine whether Git reports the root-level artifact as untracked.
     */
    protected function isUntracked(string $status): bool
    {
        return in_array('?? dump.rdb', preg_split('/\R/', trim($status)) ?: [], true);
    }

    /**
     * Confirm the generated Redis program predates its explicit data directory.
     */
    protected function usesApplicationWorkingDirectory(string $configuration): bool
    {
        $start = strpos($configuration, '[program:redis]');

        if ($start === false) {
            return false;
        }

        $program = substr($configuration, $start);
        $next = strpos($program, "\n[program:", 1);

        if ($next !== false) {
            $program = substr($program, 0, $next);
        }

        return str_contains($program, 'command=/usr/bin/redis-server')
            && preg_match('/(?:^|\s)--dir(?:\s|=)/', $program) !== 1;
    }

    /**
     * Verify the binary header without reading a potentially large snapshot into memory.
     */
    protected function hasRedisHeader(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 9);
        } finally {
            fclose($handle);
        }

        return is_string($header) && preg_match('/^REDIS[0-9]{4}$/D', $header) === 1;
    }
}
