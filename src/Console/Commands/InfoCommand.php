<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use Laravel\Prompts\Elements\KeyValueList;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Endpoints;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\callout;

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
                'path_repository_mounts' => $manifest->pathRepositoryMounts,
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

            callout(
                $manifest->name,
                [new KeyValueList($this->keyValueMap($this->rows(
                    $manifest,
                    $state,
                    $resolvedEndpoints,
                    $configuredImage,
                    $outdated,
                )))],
                null,
                $state,
            );
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
     * Build human-readable detail rows, identity first, then endpoints, then
     * the application stack, then the container. The instance name is not
     * included; it is the callout label.
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
            ['Branch', $manifest->branch],
            ['State', $state],
        ];

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

        $rows[] = ['PHP', "{$manifest->php} / PHP-FPM"];
        $rows[] = ['Frontend', $manifest->frontend];
        $rows[] = ['Services', $manifest->services === [] ? 'none' : implode(', ', $manifest->services)];
        $rows[] = ['Processes', $manifest->processes === [] ? 'none' : implode(', ', $manifest->processes)];

        if ($manifest->cpus !== null && $manifest->memory !== null) {
            $rows[] = ['Resources', "{$manifest->cpus} CPU / {$manifest->memory}"];
        }

        $rows[] = ['Runtime', $manifest->runtime];
        $rows[] = ['Image', $this->imageSummary($manifest, $configuredImage, $outdated)];

        return $rows;
    }

    /**
     * Describe the recorded image and how it compares with what's configured now.
     */
    private function imageSummary(Manifest $manifest, string $configuredImage, ?bool $outdated): string
    {
        if ($manifest->image === null) {
            return 'unknown (legacy manifest)';
        }

        return $manifest->image.' ('.match ($outdated) {
            true => "outdated, configured: {$configuredImage}",
            false => 'current',
            null => 'unknown',
        }.')';
    }

    /**
     * Convert row pairs into a duplicate-safe key/value map.
     *
     * Endpoint labels are derived from configured preview names (see
     * Endpoints::all()), which are only barred from matching a handful of
     * built-in endpoint names (app, application, mysql, pgsql, redis,
     * mailpit). Nothing stops a preview from being named e.g. "runtime" or
     * "php", which would ucfirst() into the same label as one of this
     * command's own fixed rows. KeyValueList takes array<string, string>,
     * so naively assigning by label would let a collision silently drop a
     * row. Disambiguate instead.
     *
     * @param  list<array{string, string}>  $rows
     * @return array<string, string>
     */
    private function keyValueMap(array $rows): array
    {
        $map = [];

        foreach ($rows as [$label, $value]) {
            $key = $label;
            $suffix = 2;

            while (array_key_exists($key, $map)) {
                $key = "{$label} ({$suffix})";
                $suffix++;
            }

            $map[$key] = $value;
        }

        return $map;
    }
}
