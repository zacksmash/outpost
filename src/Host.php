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
     * Get the IDs of host [container exec] client processes attached to the container.
     *
     * These are the host-side CLI clients streaming an exec session. When
     * one wedges on a dead exec socket, the container cannot be stopped
     * until the client dies, so recovery needs to find and kill them.
     *
     * @return list<int>
     */
    public function execClientIds(string $container): array
    {
        $result = Process::run(['pgrep', '-f', "container exec .*[[:space:]]{$container}[[:space:]]"]);

        // pgrep exits 1 to report "no matches", which is a normal answer.
        if (($result->exitCode() ?? 2) > 1) {
            throw new RuntimeException(
                "Unable to inspect host processes for [{$container}]: ".trim($result->errorOutput() ?: $result->output()),
            );
        }

        return array_values(array_map(
            intval(...),
            array_filter(preg_split('/\s+/', trim($result->output())) ?: []),
        ));
    }

    /**
     * Signal the given host processes to terminate.
     *
     * Failures are tolerated: a process that died between discovery and
     * the signal is exactly the outcome the signal wanted.
     *
     * @param  list<int>  $ids
     */
    public function terminateProcesses(array $ids, bool $force = false): void
    {
        if ($ids === []) {
            return;
        }

        Process::run(['kill', $force ? '-KILL' : '-TERM', ...array_map(strval(...), $ids)]);
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
