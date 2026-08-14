<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Endpoints;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Runtime;

use function Laravel\Prompts\error;
use function Laravel\Prompts\table;

class InfoCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:info
        {name? : The name of the instance}
        {--json : Emit machine-readable JSON}';

    /**
     * The command description.
     */
    protected $description = 'Show an instance and its service connection details';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, Runtime $runtime, Endpoints $endpoints): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            $state = $runtime->state($manifest->container) ?? 'missing';
            $resolvedEndpoints = $endpoints->all($manifest);
            $details = [
                'name' => $manifest->name,
                'container' => $manifest->container,
                'branch' => $manifest->branch,
                'state' => $state,
                'php' => $manifest->php,
                'server' => $manifest->server,
                'octane_server' => $manifest->octaneServer,
                'frontend' => $manifest->frontend,
                'services' => $manifest->services,
                'processes' => $manifest->processes,
                'resources' => [
                    'cpus' => $manifest->cpus,
                    'memory' => $manifest->memory,
                ],
                'expose_services' => $manifest->exposeServices,
                'endpoints' => $resolvedEndpoints,
            ];

            if ($this->option('json')) {
                $this->line(json_encode(
                    $details,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ));

                return self::SUCCESS;
            }

            table(['Detail', 'Value'], $this->rows($manifest, $state, $resolvedEndpoints));
        } catch (JsonException|RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Build human-readable detail rows.
     *
     * @param  array<string, array<string, int|string>>  $endpoints
     * @return list<array{string, string}>
     */
    protected function rows(Manifest $manifest, string $state, array $endpoints): array
    {
        $rows = [
            ['Name', $manifest->name],
            ['Branch', $manifest->branch],
            ['State', $state],
            ['Runtime', implode(' / ', array_filter([
                "PHP {$manifest->php}",
                $manifest->server,
                $manifest->octaneServer,
            ]))],
            ['Frontend', $manifest->frontend],
            ['Services', $manifest->services === [] ? 'none' : implode(', ', $manifest->services)],
            ['Processes', $manifest->processes === [] ? 'none' : implode(', ', $manifest->processes)],
        ];

        if ($manifest->cpus !== null && $manifest->memory !== null) {
            $rows[] = ['Resources', "{$manifest->cpus} CPU / {$manifest->memory}"];
        }

        foreach ($endpoints as $name => $endpoint) {
            $url = $endpoint['url'] ?? null;

            if (is_string($url)) {
                $rows[] = [ucfirst($name), $url];
            }

            $smtpHost = $endpoint['smtp_host'] ?? null;
            $smtpPort = $endpoint['smtp_port'] ?? null;

            if ($name === 'mailpit' && is_string($smtpHost) && is_int($smtpPort)) {
                $rows[] = ['SMTP', "{$smtpHost}:{$smtpPort}"];
            }
        }

        return $rows;
    }
}
