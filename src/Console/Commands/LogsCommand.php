<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\error;

#[AsCommand(name: 'outpost:logs')]
class LogsCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:logs
        {name? : The name of the instance}
        {--follow : Keep streaming new log output}';

    /**
     * The command description.
     */
    protected $description = "Display an instance's service logs";

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            // A follow stream ends quietly whenever the container stops, so
            // a missing container must be refused before streaming starts.
            if (! $runtime->exists($manifest->container)) {
                error("The [{$manifest->name}] instance has no container. Recreate it with [php artisan outpost:start {$manifest->name}].");

                return self::FAILURE;
            }

            $runtime->logs(
                $manifest->container,
                follow: (bool) $this->option('follow'),
                output: fn (string $type, string $buffer) => $this->output->write($buffer),
            );
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
