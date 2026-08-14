<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class PathRepositoryScan
{
    /**
     * Create a new scan result.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $paths,
        public readonly array $warnings,
    ) {}

    /**
     * Determine if the scan found any mountable path repositories.
     */
    public function any(): bool
    {
        return $this->paths !== [];
    }

    /**
     * Get the read-only volume specs for the discovered paths.
     *
     * Read-only bounds destruction, not disclosure — which is exactly why
     * the caller must put a human in front of these mounts first.
     *
     * @return list<string>
     */
    public function mounts(): array
    {
        return array_map(fn (string $path): string => "{$path}:{$path}:ro", $this->paths);
    }
}
