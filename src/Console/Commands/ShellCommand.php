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
use function Laravel\Prompts\note;

#[AsCommand(name: 'outpost:shell')]
class ShellCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:shell
        {name? : The name of the instance}
        {--root : Open the shell as root instead of the application user}';

    /**
     * The command description.
     */
    protected $description = 'Open a shell inside an instance';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            if (! $runtime->running($manifest->container)) {
                error("The [{$manifest->name}] instance is not running.");
                note("Start it first:\n\n  php artisan outpost:start {$manifest->name}");

                return self::FAILURE;
            }

            return $runtime->shell(
                $manifest->container,
                fn (string $type, string $buffer) => $this->output->write($buffer),
                root: (bool) $this->option('root'),
            );
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }
}
