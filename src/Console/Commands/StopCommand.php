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
use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;

#[AsCommand(name: 'outpost:stop')]
class StopCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:stop {name? : The name of the instance}';

    /**
     * The command description.
     */
    protected $description = 'Stop an instance; its worktree and data survive';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            $state = $runtime->state($manifest->container);

            if ($state === null) {
                info("The [{$manifest->name}] instance has no container to stop. Recreate it with [php artisan outpost:start {$manifest->name}].");

                return self::SUCCESS;
            }

            if ($state !== 'running') {
                info("The [{$manifest->name}] instance is already stopped.");

                return self::SUCCESS;
            }

            spin(fn () => $runtime->stop($manifest->container), "Stopping [{$manifest->name}]");
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        outro("Stopped [{$manifest->name}]. Start it again with [php artisan outpost:start {$manifest->name}].");

        return self::SUCCESS;
    }
}
