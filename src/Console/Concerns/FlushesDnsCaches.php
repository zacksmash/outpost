<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Concerns;

use RuntimeException;
use Zacksmash\Outpost\Contracts\RuntimeDriver;

use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

trait FlushesDnsCaches
{
    /**
     * Flush the host DNS cache without failing the surrounding command.
     *
     * The flush only prevents macOS from serving a stale address for a
     * recreated container, so a failure here must never mark an already
     * working instance as failed.
     */
    protected function flushDnsCacheQuietly(RuntimeDriver $runtime): void
    {
        try {
            $runtime->flushDnsCache();
        } catch (RuntimeException $e) {
            warning($e->getMessage());
            note('If an instance URL resolves to a stale address, flush the cache manually with [dscacheutil -flushcache].');
        }
    }
}
