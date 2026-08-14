<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Concerns;

use InvalidArgumentException;
use RuntimeException;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\error;
use function Laravel\Prompts\select;

trait ResolvesInstances
{
    /**
     * Resolve the instance the command should act on, prompting if needed.
     */
    protected function instance(Outposts $outposts): ?Manifest
    {
        if (($name = $this->instanceName($outposts)) === null) {
            return null;
        }

        try {
            $manifest = $outposts->exists($name) ? $outposts->find($name) : null;
        } catch (InvalidArgumentException|RuntimeException $e) {
            error($e->getMessage());

            return null;
        }

        if ($manifest === null) {
            error("The [{$name}] instance does not exist. See [php artisan outpost:list].");

            return null;
        }

        return $manifest;
    }

    /**
     * Determine which instance name the command should act on.
     */
    protected function instanceName(Outposts $outposts): ?string
    {
        $name = $this->argument('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $names = array_map(fn (Manifest $manifest): string => $manifest->name, $outposts->all());

        if ($names === []) {
            error('No instances exist yet. Create one with [php artisan outpost].');

            return null;
        }

        return (string) select('Which instance?', $names);
    }
}
