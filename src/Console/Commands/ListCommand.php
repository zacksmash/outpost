<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\RendersJsonOutput;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

#[AsCommand(name: 'outpost:list')]
class ListCommand extends Command
{
    use RendersJsonOutput;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:list
        {--json : Output instances as JSON}';

    /**
     * The command description.
     */
    protected $description = "List this application's Outpost instances";

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime): int
    {
        $manifests = $outposts->all();

        if ($manifests === []) {
            if ($this->option('json')) {
                $this->writeJson([]);

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
                $this->writeJson(array_map(
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
                ));

                return self::SUCCESS;
            }
        } catch (JsonException|RuntimeException $e) {
            $this->renderError($e->getMessage());

            return self::FAILURE;
        }

        table(
            ['Name', 'Branch', 'Runtime', 'Services', 'App Processes', 'State', 'Image', 'Upgrade', 'URL'],
            array_map(fn (Manifest $manifest): array => [
                $manifest->name,
                $manifest->branch,
                "PHP {$manifest->php} / FPM",
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
