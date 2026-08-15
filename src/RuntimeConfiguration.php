<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class RuntimeConfiguration
{
    /**
     * Create an Apple container runtime configuration manager.
     */
    public function __construct(
        protected readonly Filesystem $files,
        protected readonly ?string $home,
    ) {}

    /**
     * Set the machine-wide container publication domain.
     */
    public function setDomain(string $domain): void
    {
        if (preg_match(
            '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/Di',
            $domain,
        ) !== 1) {
            throw new RuntimeException('The Outpost domain must be a valid publication domain.');
        }

        $path = $this->path();
        $this->files->ensureDirectoryExists(dirname($path), 0700);

        $contents = $this->files->isFile($path) ? $this->files->get($path) : '';
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $section = null;
        $sectionEnd = count($lines);

        foreach ($lines as $index => $line) {
            // TOML allows whitespace inside a table header, so "[ dns ]"
            // must be recognized as the same section as "[dns]".
            if ($section === null && preg_match('/^\s*\[\s*dns\s*]\s*(?:#.*)?$/i', $line) === 1) {
                $section = $index;

                continue;
            }

            // The section ends at any following table or array-of-tables
            // header, including "[[mirrors]]"-style double brackets.
            if ($section !== null && preg_match('/^\s*\[.+]\s*(?:#.*)?$/', $line) === 1) {
                $sectionEnd = $index;

                break;
            }
        }

        if ($section === null) {
            if ($lines !== []) {
                $lines[] = '';
            }

            $lines[] = '[dns]';
            $lines[] = "domain = \"{$domain}\"";
        } else {
            $replaced = false;

            for ($index = $section + 1; $index < $sectionEnd; $index++) {
                if (preg_match('/^\s*domain\s*=/i', $lines[$index]) === 1) {
                    $lines[$index] = "domain = \"{$domain}\"";
                    $replaced = true;

                    break;
                }
            }

            if (! $replaced) {
                array_splice($lines, $section + 1, 0, ["domain = \"{$domain}\""]);
            }
        }

        if ($this->files->put($path, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException("Unable to update the Apple container configuration at [{$path}].");
        }
    }

    /**
     * Get the Apple container configuration path.
     */
    public function path(): string
    {
        if (! is_string($this->home)
            || $this->home === ''
            || ! str_starts_with($this->home, '/')
            || rtrim($this->home, '/') === ''
            || str_contains($this->home, "\0")
            || str_contains($this->home, "\n")
            || str_contains($this->home, "\r")) {
            throw new RuntimeException('Unable to locate the home directory for Apple container configuration.');
        }

        return rtrim($this->home, '/').'/.config/container/config.toml';
    }
}
