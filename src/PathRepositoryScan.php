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
     * @param  list<string>|null  $targets
     */
    public function __construct(
        public readonly array $paths,
        public readonly array $warnings,
        protected readonly ?array $targets = null,
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
        $mounts = [];

        foreach ($this->paths as $index => $path) {
            $mounts[] = $path.':'.($this->targets[$index] ?? $path).':ro';
        }

        return $mounts;
    }
}
