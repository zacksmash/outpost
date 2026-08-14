<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Zacksmash\Outpost\ApplicationHttps;
use Zacksmash\Outpost\Certificates;
use Zacksmash\Outpost\Detector;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\Endpoints;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\OutpostServiceProvider;
use Zacksmash\Outpost\Processes;

it('merges the package config', function () {
    expect(config('outpost'))->toBeArray();
});

it('binds the instance store as a singleton rooted at the configured path', function () {
    expect(app(Outposts::class))->toBe(app(Outposts::class))
        ->and(app(Outposts::class)->path('demo'))->toBe(base_path('.outpost').'/demo');
});

it('binds the certificate manager as a singleton', function () {
    expect(app(Certificates::class))->toBe(app(Certificates::class));
});

it('binds the primary application https detector as a singleton', function () {
    expect(app(ApplicationHttps::class))->toBe(app(ApplicationHttps::class));
});

it('binds the detector as a singleton', function () {
    expect(app(Detector::class))->toBe(app(Detector::class));
});

it('binds the endpoint resolver as a singleton', function () {
    expect(app(Endpoints::class))->toBe(app(Endpoints::class));
});

it('binds the environment doctor as a singleton', function () {
    expect(app(Doctor::class))->toBe(app(Doctor::class));
});

it('binds the git manager as a singleton', function () {
    expect(app(Git::class))->toBe(app(Git::class));
});

it('binds the process configuration as a singleton', function () {
    expect(app(Processes::class))->toBe(app(Processes::class));
});

it('publishes the package config', function () {
    $paths = ServiceProvider::pathsToPublish(OutpostServiceProvider::class, 'outpost-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths))->toBe([config_path('outpost.php')]);
});
