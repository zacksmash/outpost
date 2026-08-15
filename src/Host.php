<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class Host
{
    /**
     * Get the host operating system name.
     */
    public function operatingSystem(): string
    {
        return $this->value(['uname', '-s'], 'Unable to identify the host operating system');
    }

    /**
     * Get the host architecture.
     */
    public function architecture(): string
    {
        return $this->value(['uname', '-m'], 'Unable to identify the host architecture');
    }

    /**
     * Get the macOS product version.
     */
    public function macOSVersion(): string
    {
        return $this->value(['sw_vers', '-productVersion'], 'Unable to identify the macOS version');
    }

    /**
     * Get the host user's numeric ID for bind-mounted file access.
     */
    public function userId(): int
    {
        return $this->identity(['id', '-u'], 'Unable to identify the host user ID');
    }

    /**
     * Get the host user's primary group ID for bind-mounted file access.
     */
    public function groupId(): int
    {
        return $this->identity(['id', '-g'], 'Unable to identify the host group ID');
    }

    /**
     * Open a URL with the macOS default browser handler.
     */
    public function open(string $url): void
    {
        $result = Process::run(['open', $url]);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Unable to open [{$url}]: ".trim($result->errorOutput() ?: $result->output()),
            );
        }
    }

    /**
     * Run a host inspection command and return its trimmed output.
     *
     * @param  list<string>  $command
     */
    protected function value(array $command, string $message): string
    {
        $result = Process::run($command);

        if (! $result->successful() || trim($result->output()) === '') {
            throw new RuntimeException(
                "{$message}: ".trim($result->errorOutput() ?: $result->output()),
            );
        }

        return trim($result->output());
    }

    /**
     * Read a positive numeric host identity value.
     *
     * @param  list<string>  $command
     */
    protected function identity(array $command, string $message): int
    {
        $value = $this->value($command, $message);

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException("{$message}: received [{$value}].");
        }

        return (int) $value;
    }
}
