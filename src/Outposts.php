<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class Outposts
{
    /**
     * Create a new instance store.
     */
    public function __construct(protected readonly string $path) {}

    /**
     * Get the root path of the given instance.
     */
    public function path(string $name): string
    {
        $this->ensureValidName($name);

        return $this->path.'/'.$name;
    }

    /**
     * Get the path to the given instance's git worktree.
     */
    public function worktreePath(string $name): string
    {
        return $this->path($name).'/app';
    }

    /**
     * Get the path to the given instance's generated runtime configuration.
     */
    public function runtimePath(string $name): string
    {
        return $this->path($name).'/runtime';
    }

    /**
     * Get the path to the given instance's manifest.
     */
    public function manifestPath(string $name): string
    {
        return $this->path($name).'/outpost.json';
    }

    /**
     * Determine if an instance with the given name exists.
     */
    public function exists(string $name): bool
    {
        return File::exists($this->manifestPath($name));
    }

    /**
     * Write the given manifest to disk.
     */
    public function save(Manifest $manifest): void
    {
        File::ensureDirectoryExists($this->path($manifest->name));

        $json = json_encode(
            $manifest->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if (File::put($this->manifestPath($manifest->name), $json.PHP_EOL) === false) {
            throw new RuntimeException("Unable to write the manifest for instance [{$manifest->name}].");
        }
    }

    /**
     * Write the given runtime configuration files for the instance.
     *
     * @param  array<string, string>  $files
     */
    public function writeRuntime(string $name, array $files): void
    {
        File::ensureDirectoryExists($path = $this->runtimePath($name));

        foreach ($files as $file => $contents) {
            if (File::put($path.'/'.$file, $contents) === false) {
                throw new RuntimeException("Unable to write the runtime configuration for instance [{$name}].");
            }
        }
    }

    /**
     * Find the manifest for the given instance.
     */
    public function find(string $name): ?Manifest
    {
        $path = $this->manifestPath($name);

        if (! File::exists($path)) {
            return null;
        }

        $data = json_decode(File::get($path), true);

        if (! is_array($data)) {
            throw new RuntimeException("The manifest at [{$path}] contains invalid JSON.");
        }

        $manifest = Manifest::fromArray($data);

        // The manifest lives inside the worktree's parent directory, so it
        // must be treated as untrusted input: refuse one that claims a
        // different name or targets an unexpected container.
        if ($manifest->name !== $name) {
            throw new RuntimeException("The manifest at [{$path}] does not belong to the [{$name}] instance.");
        }

        if (Str::slug($manifest->container) !== $manifest->container
            || ! str_starts_with($manifest->container, "{$name}-")) {
            throw new RuntimeException("The manifest at [{$path}] names an unexpected container [{$manifest->container}].");
        }

        return $manifest;
    }

    /**
     * Get the manifests of every instance.
     *
     * Stray directories and unreadable manifests are skipped so one broken
     * instance can never make the others unlistable; resolve a broken one
     * by name to see what is wrong with it.
     *
     * @return list<Manifest>
     */
    public function all(): array
    {
        if (! File::isDirectory($this->path)) {
            return [];
        }

        $manifests = [];

        foreach (File::directories($this->path) as $directory) {
            $name = basename($directory);

            if ($name === '' || Str::slug($name) !== $name) {
                continue;
            }

            try {
                if ($manifest = $this->find($name)) {
                    $manifests[] = $manifest;
                }
            } catch (InvalidArgumentException|RuntimeException) {
                continue;
            }
        }

        return $manifests;
    }

    /**
     * Delete the given instance's directory entirely.
     */
    public function delete(string $name): void
    {
        $path = $this->path($name);

        if (! File::isDirectory($path)) {
            return;
        }

        if (! File::deleteDirectory($path)) {
            throw new RuntimeException("Unable to delete the instance directory at [{$path}].");
        }
    }

    /**
     * Ensure the given instance name is a safe, URL-friendly slug.
     */
    protected function ensureValidName(string $name): void
    {
        if ($name === '' || Str::slug($name) !== $name) {
            throw new InvalidArgumentException("The instance name [{$name}] must be a URL-friendly slug.");
        }
    }
}
