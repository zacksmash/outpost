<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Zacksmash\Outpost\Detector;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\OutpostServiceProvider;

it('merges the package config', function () {
    expect(config('outpost'))->toBeArray();
});

it('binds the instance store as a singleton rooted at the configured path', function () {
    expect(app(Outposts::class))->toBe(app(Outposts::class))
        ->and(app(Outposts::class)->path('demo'))->toBe(base_path('.outpost').'/demo');
});

it('binds the detector as a singleton', function () {
    expect(app(Detector::class))->toBe(app(Detector::class));
});

it('publishes the package config', function () {
    $paths = ServiceProvider::pathsToPublish(OutpostServiceProvider::class, 'outpost-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths))->toBe([config_path('outpost.php')]);
});
