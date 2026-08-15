<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use RuntimeException;

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

    /**
     * Mirror container targets with host-side links inside the instance root.
     *
     * Composer creates vendor links relative to /app. The same relative link
     * resolves from the host worktree into this mirrored tree, keeping host
     * Artisan, IDE, Herd, and Boost usage functional without making the
     * external repository writable inside the instance.
     */
    public function createHostBridges(string $instance): void
    {
        $instance = rtrim($instance, '/');

        if ($instance === '' || ! is_dir($instance) || is_link($instance)) {
            throw new RuntimeException("Unable to create path repository links under [{$instance}].");
        }

        foreach ($this->paths as $index => $path) {
            $target = $this->targets[$index] ?? $path;
            $segments = $this->targetSegments($target);
            $source = realpath($path);
            $parent = $instance;

            if ($source === false || ! is_dir($source)) {
                throw new RuntimeException("Unable to create the host path repository link because [{$path}] is no longer a directory.");
            }

            foreach (array_slice($segments, 0, -1) as $segment) {
                $parent .= '/'.$segment;

                if (is_link($parent) || (file_exists($parent) && ! is_dir($parent))) {
                    throw new RuntimeException("Unable to create the host path repository link because [{$parent}] already exists.");
                }

                if (! is_dir($parent) && ! @mkdir($parent, 0755) && ! is_dir($parent)) {
                    throw new RuntimeException("Unable to create the host path repository directory [{$parent}].");
                }
            }

            $bridge = $parent.'/'.$segments[array_key_last($segments)];

            if (is_link($bridge) && realpath($bridge) === $source) {
                continue;
            }

            if (is_link($bridge) || file_exists($bridge)) {
                throw new RuntimeException("Unable to create the host path repository link because [{$bridge}] already exists.");
            }

            if (! @symlink($path, $bridge)) {
                throw new RuntimeException("Unable to link the host path repository [{$path}] at [{$bridge}].");
            }
        }
    }

    /**
     * Validate and split an absolute container target.
     *
     * @return non-empty-list<string>
     */
    protected function targetSegments(string $target): array
    {
        $segments = explode('/', ltrim($target, '/'));

        if (! str_starts_with($target, '/')
            || $segments === ['']
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || in_array($segments[0], ['app', 'runtime', 'outpost.json'], true)
            || ($segments[0] === 'etc' && ($segments[1] ?? null) === 'outpost')) {
            throw new RuntimeException("The path repository target [{$target}] is not a safe absolute path.");
        }

        return $segments;
    }
}
