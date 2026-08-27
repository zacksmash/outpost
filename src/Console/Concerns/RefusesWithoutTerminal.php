<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Concerns;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

trait RefusesWithoutTerminal
{
    /**
     * Refuse to fall back to a declined confirmation without a terminal.
     *
     * Without a terminal there is nobody to approve a destructive action,
     * so the confirmation prompt would silently take its "no" default
     * while the command still exits successfully. Consent must arrive as
     * the explicit --force option instead, and the remedy names the exact
     * command — including the caller's mode flags — to run again.
     */
    protected function refusesWithoutTerminal(string $action, string $remedy): bool
    {
        if ($this->input->isInteractive()) {
            return false;
        }

        error("Refusing to {$action} non-interactively without --force.");
        note("Run it again with explicit consent:\n\n  {$remedy}");

        return true;
    }
}
