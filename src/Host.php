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
}
