<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Endpoints;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\error;
use function Laravel\Prompts\outro;

class OpenCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:open
        {name? : The name of the instance}
        {endpoint=app : Browser endpoint: app or mailpit}';

    /**
     * The command description.
     */
    protected $description = 'Open an instance in the default browser, starting it if needed';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime, Host $host, Endpoints $endpoints): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            $endpoint = $this->argument('endpoint');
            $url = $endpoints->browser(
                $manifest,
                is_string($endpoint) ? $endpoint : 'app',
            );

            if (! $runtime->running($manifest->container)
                && $this->call('outpost:start', ['name' => $manifest->name]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            $host->open($url);
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        outro("Opened: {$url}");

        return self::SUCCESS;
    }
}
