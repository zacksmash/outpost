<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use RuntimeException;

class CommandConfiguration
{
    /**
     * Validate and resolve a named collection of shell-free commands.
     *
     * @return array<string, list<string>>
     */
    public function resolve(
        mixed $configured,
        string $path,
        string $subject,
        string $php,
        bool $rejectSemicolons = false,
    ): array {
        if (! is_array($configured)) {
            throw new RuntimeException("The [{$path}] value must be an associative array.");
        }

        $commands = [];

        foreach ($configured as $name => $command) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
                throw new RuntimeException(
                    "Outpost {$subject} names must start with a lowercase letter and may only contain lowercase letters, numbers, dashes, and underscores.",
                );
            }

            if (! is_array($command) || ! array_is_list($command) || $command === []) {
                throw new RuntimeException(
                    "The [{$path}.{$name}] command must be a non-empty list of argument strings.",
                );
            }

            $arguments = [];

            foreach ($command as $argument) {
                if (! is_string($argument)
                    || $argument === ''
                    || str_contains($argument, "\n")
                    || str_contains($argument, "\r")
                    || str_contains($argument, "\0")
                    || ($rejectSemicolons && str_contains($argument, ';'))) {
                    $suffix = $rejectSemicolons ? ' without semicolons' : '';

                    throw new RuntimeException(
                        "The [{$path}.{$name}] command must contain only non-empty, single-line argument strings{$suffix}.",
                    );
                }

                $arguments[] = $argument === '@php' ? "php{$php}" : $argument;
            }

            $commands[$name] = $arguments;
        }

        return $commands;
    }
}
