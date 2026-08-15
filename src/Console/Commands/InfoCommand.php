<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Endpoints;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

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
    public function handle(Outposts $outposts, RuntimeDriver $runtime, Endpoints $endpoints): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            $state = $runtime->instanceState($manifest);
            $resolvedEndpoints = $endpoints->all($manifest);
            $configuredImage = config()->string('outpost.image');
            $configuredImageDigest = $runtime->imageMetadata($configuredImage)['digest'] ?? null;
            $outdated = $manifest->imageOutdated($configuredImage, $configuredImageDigest);
            $details = [
                'name' => $manifest->name,
                'container' => $manifest->container,
                'runtime' => $manifest->runtime,
                'branch' => $manifest->branch,
                'state' => $state,
                'status' => $runtime->instanceStatus($manifest, $state),
                'image' => $manifest->image,
                'image_digest' => $manifest->imageDigest,
                'configured_image' => $configuredImage,
                'configured_image_digest' => $configuredImageDigest,
                'outdated' => $outdated,
                'php' => $manifest->php,
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

            table(['Detail', 'Value'], $this->rows(
                $manifest,
                $state,
                $resolvedEndpoints,
                $configuredImage,
                $outdated,
            ));
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
    protected function rows(
        Manifest $manifest,
        string $state,
        array $endpoints,
        string $configuredImage,
        ?bool $outdated,
    ): array {
        $rows = [
            ['Name', $manifest->name],
            ['Branch', $manifest->branch],
            ['Container runtime', $manifest->runtime],
            ['State', $state],
            ['Image', $manifest->image ?? 'unknown (legacy manifest)'],
            ['Image status', match ($outdated) {
                true => "outdated; configured image is {$configuredImage}",
                false => 'current',
                null => 'unknown',
            }],
            ['Runtime', "PHP {$manifest->php} / PHP-FPM"],
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
