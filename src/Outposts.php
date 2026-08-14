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

        return Manifest::fromArray($data);
    }

    /**
     * Get the manifests of every instance.
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
            if ($manifest = $this->find(basename($directory))) {
                $manifests[] = $manifest;
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
