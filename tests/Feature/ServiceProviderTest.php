<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Zacksmash\Outpost\OutpostServiceProvider;

it('merges the package config', function () {
    expect(config('outpost'))->toBeArray();
});

it('publishes the package config', function () {
    $paths = ServiceProvider::pathsToPublish(OutpostServiceProvider::class, 'outpost-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths))->toBe([config_path('outpost.php')]);
});
