<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class Processes
{
    /**
     * Create an application process configuration reader.
     */
    public function __construct(protected readonly Repository $config) {}

    /**
     * Resolve every configured process command for an instance PHP version.
     *
     * @return array<string, list<string>>
     */
    public function commands(string $php): array
    {
        $configured = $this->config->get('outpost.processes', []);

        if (! is_array($configured)) {
            throw new RuntimeException('The [outpost.processes] value must be an associative array.');
        }

        $commands = [];

        foreach ($configured as $name => $command) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
                throw new RuntimeException(
                    'Outpost process names must start with a lowercase letter and may only contain lowercase letters, numbers, dashes, and underscores.',
                );
            }

            if (! is_array($command) || ! array_is_list($command) || $command === []) {
                throw new RuntimeException(
                    "The [outpost.processes.{$name}] command must be a non-empty list of argument strings.",
                );
            }

            $arguments = [];

            foreach ($command as $argument) {
                if (! is_string($argument)
                    || $argument === ''
                    || str_contains($argument, "\n")
                    || str_contains($argument, "\r")
                    || str_contains($argument, "\0")
                    || str_contains($argument, ';')) {
                    throw new RuntimeException(
                        "The [outpost.processes.{$name}] command must contain only non-empty, single-line argument strings without semicolons.",
                    );
                }

                $arguments[] = $argument === '@php' ? "php{$php}" : $argument;
            }

            $commands[$name] = $arguments;
        }

        return $commands;
    }
}
