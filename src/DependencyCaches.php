<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

class DependencyCaches
{
    /**
     * Container paths used only for package-manager download caches.
     */
    public const string COMPOSER_TARGET = '/var/cache/outpost/composer';

    public const string NPM_TARGET = '/var/cache/outpost/npm';

    public function __construct(
        protected readonly Outposts $outposts,
        protected readonly Filesystem $files,
    ) {}

    /**
     * Prepare the repository-local caches and return their bind mounts.
     *
     * @return list<string>
     */
    public function mounts(): array
    {
        $composer = $this->prepare('Composer', $this->outposts->dependencyCachePath('composer'));
        $npm = $this->prepare('npm', $this->outposts->dependencyCachePath('npm'));

        return [
            "{$composer}:".self::COMPOSER_TARGET,
            "{$npm}:".self::NPM_TARGET,
        ];
    }

    /**
     * Point package managers at the mounted caches in new containers.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            'COMPOSER_CACHE_DIR' => self::COMPOSER_TARGET,
            'NPM_CONFIG_CACHE' => self::NPM_TARGET,
        ];
    }

    /**
     * Ensure a host cache path is a writable directory.
     */
    protected function prepare(string $manager, string $path): string
    {
        if ($this->files->exists($path) && ! $this->files->isDirectory($path)) {
            throw new RuntimeException("Unable to prepare the {$manager} dependency cache at [{$path}]: the path is not a directory.");
        }

        try {
            $this->files->ensureDirectoryExists($path);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Unable to prepare the {$manager} dependency cache at [{$path}].",
                previous: $e,
            );
        }

        if (! $this->files->isDirectory($path) || ! $this->files->isWritable($path)) {
            throw new RuntimeException("Unable to prepare the {$manager} dependency cache at [{$path}]: the directory is not writable.");
        }

        return $path;
    }
}
