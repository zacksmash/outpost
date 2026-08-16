<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Prompts\Elements\BulletedList;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\RendersJsonOutput;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\callout;
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
            ['Name', 'Branch', 'State', 'URL'],
            array_map(fn (Manifest $manifest): array => [
                $manifest->name,
                $manifest->branch,
                $instanceStates[$manifest->name],
                $manifest->url,
            ], $manifests),
        );

        $this->renderSummary($manifests, $instanceStates, $outdated);

        return self::SUCCESS;
    }

    /**
     * Render the aggregate summary callout beneath the instance table.
     *
     * @param  list<Manifest>  $manifests
     * @param  array<string, string>  $instanceStates
     * @param  array<string, ?bool>  $outdated
     */
    private function renderSummary(array $manifests, array $instanceStates, array $outdated): void
    {
        $stateCounts = [];

        foreach ($instanceStates as $state) {
            $stateCounts[$state] = ($stateCounts[$state] ?? 0) + 1;
        }

        ksort($stateCounts);
        uasort($stateCounts, fn (int $a, int $b): int => $b <=> $a);

        $breakdown = implode(', ', array_map(
            fn (string $state, int $count): string => "{$count} {$state}",
            array_keys($stateCounts),
            array_values($stateCounts),
        ));

        $outdatedCount = count(array_filter($outdated, fn (?bool $isOutdated): bool => $isOutdated === true));

        $imageLine = $outdatedCount === 0
            ? 'All images current'
            : "{$outdatedCount} outdated — run php artisan outpost:upgrade";

        $instanceCount = count($manifests);

        callout(
            "{$instanceCount} ".Str::plural('instance', $instanceCount),
            [
                new BulletedList([$breakdown, $imageLine]),
                'Details: php artisan outpost:info <name>',
            ],
            $outdatedCount > 0 ? 'warning' : null,
        );
    }
}
