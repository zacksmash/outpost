<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Runtime;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

class ReloadCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:reload {name? : The name of the instance}';

    /**
     * The command description.
     */
    protected $description = 'Reload the Laravel Octane workers in an instance';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, Runtime $runtime): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        if ($manifest->server !== 'octane') {
            error("The [{$manifest->name}] instance does not use Octane.");

            return self::FAILURE;
        }

        try {
            if (! $runtime->running($manifest->container)) {
                error("The [{$manifest->name}] instance is not running.");
                note("Start it first:\n\n  php artisan outpost:start {$manifest->name}");

                return self::FAILURE;
            }

            $exit = $runtime->run(
                $manifest->container,
                ["php{$manifest->php}", 'artisan', 'octane:reload'],
                fn (string $type, string $buffer) => $this->output->write($buffer),
            );
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        if ($exit === self::SUCCESS) {
            outro("Reloaded [{$manifest->name}].");
        }

        return $exit;
    }
}
