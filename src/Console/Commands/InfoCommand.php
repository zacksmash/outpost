<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Endpoints;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

#[AsCommand(name: 'outpost:info')]
class InfoCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:info
        {name? : The name of the instance}
        {--json : Output instance details as JSON}';

    /**
     * The command description.
     */
    protected $description = 'Display instance details, endpoints, and credentials';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime, Endpoints $endpoints): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            $runtimeState = $runtime->state($manifest->container) ?? 'missing';
            $state = $runtime->instanceState($manifest, $runtimeState);
            $status = $runtime->instanceStatus($manifest, $runtimeState);
            $resolvedEndpoints = $endpoints->all($manifest);
            $configuredImage = config()->string('outpost.image');
            $configuredImageDigest = $runtime->imageMetadata($configuredImage)['digest'] ?? null;
            $outdated = $manifest->imageOutdated($configuredImage, $configuredImageDigest);
            $processStates = $this->option('json')
                ? $this->processStates($manifest, $runtime, $runtimeState)
                : [];
            $details = [
                'name' => $manifest->name,
                'container' => $manifest->container,
                'runtime' => $manifest->runtime,
                'branch' => $manifest->branch,
                'state' => $state,
                'status' => $status,
                'image' => $manifest->image,
                'image_digest' => $manifest->imageDigest,
                'configured_image' => $configuredImage,
                'configured_image_digest' => $configuredImageDigest,
                'outdated' => $outdated,
                'php' => $manifest->php,
                'frontend' => $manifest->frontend,
                'services' => $manifest->services,
                'processes' => $manifest->processes,
                'process_states' => $processStates,
                'resources' => [
                    'cpus' => $manifest->cpus,
                    'memory' => $manifest->memory,
                ],
                'expose_services' => $manifest->exposeServices,
                'endpoints' => $resolvedEndpoints,
            ];

            if ($this->option('json')) {
                $this->writeJson($details);

                return self::SUCCESS;
            }

            $this->newLine();

            foreach ($this->rows(
                $manifest,
                $state,
                $resolvedEndpoints,
                $configuredImage,
                $outdated,
            ) as [$label, $value]) {
                $this->components->twoColumnDetail($label, $value);
            }

            $this->newLine();
        } catch (JsonException|RuntimeException $e) {
            $this->renderError($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Resolve truthful process states without executing in a stopped container.
     *
     * @return array<string, string>
     */
    protected function processStates(Manifest $manifest, RuntimeDriver $runtime, string $runtimeState): array
    {
        if ($manifest->processes === []) {
            return [];
        }

        if ($runtimeState !== 'running') {
            return array_fill_keys($manifest->processes, 'unavailable');
        }

        if ($manifest->status !== 'ready') {
            return array_fill_keys($manifest->processes, 'waiting');
        }

        $states = [];

        foreach ($manifest->processes as $process) {
            try {
                $states[$process] = $runtime->processStates(
                    $manifest->container,
                    [$process],
                )[$process]['state'];
            } catch (RuntimeException) {
                $states[$process] = 'unknown';
            }
        }

        return $states;
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

            $note = $endpoint['note'] ?? null;

            if (is_string($note)) {
                $rows[] = [ucfirst($name).' note', $note];
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
