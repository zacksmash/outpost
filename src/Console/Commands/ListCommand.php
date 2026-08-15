<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Runtime;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class ListCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:list
        {--json : Emit machine-readable JSON}';

    /**
     * The command description.
     */
    protected $description = 'List the instances of this application';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, Runtime $runtime): int
    {
        $manifests = $outposts->all();

        if ($manifests === []) {
            if ($this->option('json')) {
                $this->line('[]');

                return self::SUCCESS;
            }

            info('No instances yet. Create one with [php artisan outpost].');

            return self::SUCCESS;
        }

        try {
            $states = $runtime->states();
            $instanceStates = [];

            foreach ($manifests as $manifest) {
                $instanceStates[$manifest->name] = $runtime->instanceState(
                    $manifest,
                    $states[$manifest->container] ?? 'missing',
                );
            }

            $configuredImage = config()->string('outpost.image');
            $configuredImageDigest = $runtime->imageMetadata($configuredImage)['digest'] ?? null;
            $outdated = [];

            foreach ($manifests as $manifest) {
                $outdated[$manifest->name] = $manifest->imageOutdated(
                    $configuredImage,
                    $configuredImageDigest,
                );
            }

            if ($this->option('json')) {
                $this->line(json_encode(array_map(
                    fn (Manifest $manifest): array => [
                        ...$manifest->toArray(),
                        'state' => $instanceStates[$manifest->name],
                        'status' => $runtime->instanceStatus(
                            $manifest,
                            $instanceStates[$manifest->name],
                        ),
                        'configured_image' => $configuredImage,
                        'configured_image_digest' => $configuredImageDigest,
                        'outdated' => $outdated[$manifest->name],
                    ],
                    $manifests,
                ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
        } catch (JsonException|RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        table(
            ['Name', 'Branch', 'PHP', 'Services', 'Processes', 'State', 'Image', 'Upgrade', 'URL'],
            array_map(fn (Manifest $manifest): array => [
                $manifest->name,
                $manifest->branch,
                $manifest->php,
                $manifest->services === [] ? '—' : implode(', ', $manifest->services),
                $manifest->processes === [] ? '—' : implode(', ', $manifest->processes),
                $instanceStates[$manifest->name],
                $manifest->image ?? 'unknown',
                match ($outdated[$manifest->name]) {
                    true => 'outdated',
                    false => 'current',
                    null => 'unknown',
                },
                $manifest->url,
            ], $manifests),
        );

        return self::SUCCESS;
    }
}
