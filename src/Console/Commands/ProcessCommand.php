<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

#[AsCommand(name: 'outpost:process')]
class ProcessCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:process
        {name? : The name of the instance}
        {process? : The configured application process}
        {--restart : Restart the selected process before reporting its state}
        {--json : Emit machine-readable JSON}';

    /**
     * The command description.
     */
    protected $description = 'Inspect or restart supervised application processes';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        $selected = $this->argument('process');
        $selected = is_string($selected) && $selected !== '' ? $selected : null;

        if ($this->option('restart') && $selected === null) {
            error('Choose a configured process to restart.');

            return self::FAILURE;
        }

        if ($selected !== null && ! in_array($selected, $manifest->processes, true)) {
            $configured = $manifest->processes === [] ? 'none' : implode(', ', $manifest->processes);
            error("The [{$selected}] process is not configured for [{$manifest->name}]. Configured processes: {$configured}.");

            return self::FAILURE;
        }

        $processes = $selected === null ? $manifest->processes : [$selected];

        if ($processes === []) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'name' => $manifest->name,
                    'processes' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                info("The [{$manifest->name}] instance has no configured application processes.");
            }

            return self::SUCCESS;
        }

        try {
            if (! $runtime->running($manifest->container)) {
                error("The [{$manifest->name}] instance is not running. Start it with [php artisan outpost:start {$manifest->name}].");

                return self::FAILURE;
            }

            if ($manifest->status !== 'ready') {
                error("The [{$manifest->name}] provisioning status is [{$manifest->status}]. Application processes are not available until provisioning is ready.");

                return self::FAILURE;
            }

            if ($this->option('restart') && $selected !== null) {
                $runtime->restartProcess($manifest->container, $selected);
            }

            $states = $runtime->processStates($manifest->container, $processes);
            $report = [
                'name' => $manifest->name,
                'processes' => array_map(fn (string $name, array $status): array => [
                    'name' => $name,
                    'state' => $status['state'],
                    'details' => $status['details'],
                ], array_keys($states), array_values($states)),
            ];

            if ($this->option('json')) {
                $this->line(json_encode(
                    $report,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ));

                return self::SUCCESS;
            }

            table(
                ['Process', 'State', 'Details'],
                array_map(fn (array $process): array => [
                    $process['name'],
                    $process['state'],
                    $process['details'],
                ], $report['processes']),
            );

            if ($this->option('restart') && $selected !== null) {
                outro("Restarted [{$selected}] in [{$manifest->name}].");
            }
        } catch (JsonException|RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
