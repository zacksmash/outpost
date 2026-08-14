<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Runtime;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;

class StartCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:start {name? : The name of the instance}';

    /**
     * The command description.
     */
    protected $description = 'Start a stopped instance';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, Runtime $runtime): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            if ($runtime->running($manifest->container)) {
                info("The [{$manifest->name}] instance is already running: {$manifest->url}");

                return self::SUCCESS;
            }

            spin(fn () => $runtime->start($manifest->container), "Starting [{$manifest->name}]");

            $seconds = config()->integer('outpost.timeout');

            if (! spin(fn () => $runtime->awaitReady($manifest->container, $seconds), 'Waiting for the instance to answer')) {
                error("The instance started but did not answer HTTP within {$seconds} seconds.");
                note("Check its logs with:\n\n  php artisan outpost:logs {$manifest->name}");

                return self::FAILURE;
            }

            $runtime->flushDnsCache();
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        outro("Started: {$manifest->url}");

        return self::SUCCESS;
    }
}
