<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PathRepositories
{
    /**
     * Create a new scanner instance.
     */
    public function __construct(protected readonly ?string $home = null) {}

    /**
     * Find composer path repositories that live outside the given worktree.
     *
     * The repository list is read from a file inside the writable worktree,
     * so treat every result as untrusted input: the caller must show these
     * paths to a human and default to mounting nothing.
     */
    public function scan(string $worktree): PathRepositoryScan
    {
        if (! File::exists($worktree.'/composer.json')) {
            return new PathRepositoryScan([], []);
        }

        $repositories = data_get(File::json($worktree.'/composer.json'), 'repositories');

        if (! is_array($repositories)) {
            return new PathRepositoryScan([], []);
        }

        $paths = [];
        $warnings = [];

        foreach ($repositories as $repository) {
            if (data_get($repository, 'type') !== 'path' || ! is_string($url = data_get($repository, 'url'))) {
                continue;
            }

            if (Str::contains($url, ['*', '?'])) {
                $warnings[] = "Skipping the glob path repository [{$url}] — mount it manually if the instance needs it.";

                continue;
            }

            $path = $this->normalize(
                str_starts_with($url, '/') ? $url : $worktree.'/'.$url,
            );

            if ($this->inside($path, $this->normalize($worktree))) {
                continue;
            }

            if (str_contains($path, ':')) {
                $warnings[] = "The path repository [{$path}] contains a colon, which breaks volume specs, so it will not be mounted.";

                continue;
            }

            if ($this->sensitive($path)) {
                $warnings[] = "The path repository [{$path}] points at a sensitive location and will never be mounted.";

                continue;
            }

            if (! File::isDirectory($path)) {
                $warnings[] = "The path repository [{$path}] is not a directory on this machine, so it will not be mounted.";

                continue;
            }

            if (! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return new PathRepositoryScan($paths, $warnings);
    }

    /**
     * Normalize a path lexically, resolving "." and ".." segments.
     *
     * Deliberately not realpath(): the decision is made on the path as
     * written, without following symlinks or requiring existence.
     */
    protected function normalize(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * Determine if the given path lives inside the given directory.
     */
    protected function inside(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, $directory.'/');
    }

    /**
     * Determine if the given path is too sensitive to ever mount.
     *
     * The home directory, anything above it, hidden directories directly
     * beneath it (.ssh, .aws, .gnupg, ...), and ~/Library are off the
     * table no matter what any confirmation or flag says. Paths are
     * canonicalized first — macOS filesystems are case-insensitive
     * and symlinks resolve at mount time, so judging the path as
     * written would let alternate spellings through. When the
     * home directory cannot be determined, nothing mounts.
     */
    protected function sensitive(string $path): bool
    {
        if ($path === '/') {
            return true;
        }

        if ($this->home === null) {
            return true;
        }

        $path = strtolower($this->canonicalize($path));
        $home = strtolower($this->canonicalize($this->home));

        return $path === '/'
            || $path === $home
            || str_starts_with($home, $path.'/')
            || str_starts_with($path, $home.'/.')
            || $path === $home.'/library'
            || str_starts_with($path, $home.'/library/');
    }

    /**
     * Canonicalize a path the way the mount will actually resolve it.
     */
    protected function canonicalize(string $path): string
    {
        if (($real = realpath($path)) !== false) {
            $path = $real;
        }

        // The macOS data volume firmlink makes /System/Volumes/Data/Users/...
        // the same directory as /Users/...; compare the canonical spelling.
        if (str_starts_with(strtolower($path), '/system/volumes/data/')) {
            $path = substr($path, strlen('/System/Volumes/Data'));
        }

        return $path;
    }
}
