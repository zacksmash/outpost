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

class ExecCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:exec
        {name? : The name of the instance}
        {arguments?* : The command and arguments to run}
        {--root : Run the command as root instead of the application user}';

    /**
     * The command description.
     */
    protected $description = 'Run a non-interactive command inside an instance';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, Runtime $runtime): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        $command = $this->argument('arguments');

        if (! is_array($command) || ! array_is_list($command) || $command === []) {
            error('Provide the command to run after the instance name.');
            note("For command options, separate Artisan and the instance command with [--]:\n\n  php artisan outpost:exec {$manifest->name} -- php artisan test --filter=Feature");

            return self::FAILURE;
        }

        foreach ($command as $argument) {
            if (! is_string($argument) || $argument === '') {
                error('Every instance command argument must be a non-empty string.');

                return self::FAILURE;
            }
        }

        try {
            if (! $runtime->running($manifest->container)) {
                error("The [{$manifest->name}] instance is not running.");
                note("Start it first:\n\n  php artisan outpost:start {$manifest->name}");

                return self::FAILURE;
            }

            return $runtime->run(
                $manifest->container,
                $command,
                fn (string $type, string $buffer) => $this->output->write($buffer),
                root: (bool) $this->option('root'),
            );
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }
}
