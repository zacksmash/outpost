<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Concerns;

use Illuminate\Console\Command;
use JsonException;

use function Laravel\Prompts\error;

/**
 * @mixin Command
 */
trait RendersJsonOutput
{
    /**
     * Determine whether this command requested machine-readable output.
     */
    protected function wantsJsonOutput(): bool
    {
        return $this->hasOption('json') && (bool) $this->option('json');
    }

    /**
     * Write one consistently formatted JSON document.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws JsonException
     */
    protected function writeJson(array $payload): void
    {
        $this->line(json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Render a human error or a parseable JSON error document.
     */
    protected function renderError(string $message): void
    {
        if ($this->wantsJsonOutput()) {
            $this->writeJson(['error' => $message]);

            return;
        }

        error($message);
    }
}
