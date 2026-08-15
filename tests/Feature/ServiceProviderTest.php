<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Zacksmash\Outpost\Certificates;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Detector;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\Endpoints;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\OutpostServiceProvider;
use Zacksmash\Outpost\Processes;
use Zacksmash\Outpost\Runtime;

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

it('binds the runtime contract to the apple container driver', function () {
    expect(app(RuntimeDriver::class))
        ->toBe(app(RuntimeDriver::class))
        ->toBeInstanceOf(Runtime::class)
        ->and(app(RuntimeDriver::class)->id())->toBe('apple-container');
});

it('allows an application to replace the runtime contract binding', function () {
    $driver = Mockery::mock(RuntimeDriver::class);
    $driver->shouldReceive('pull')
        ->once()
        ->with(Runtime::PUBLISHED_IMAGE, Mockery::type('callable'));

    app()->instance(RuntimeDriver::class, $driver);

    $this->artisan('outpost:pull', ['--force' => true])->assertSuccessful();
});

it('publishes the package config', function () {
    $paths = ServiceProvider::pathsToPublish(OutpostServiceProvider::class, 'outpost-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths))->toBe([config_path('outpost.php')]);
});

it('registers the focused command surface', function () {
    expect(Artisan::all())
        ->toHaveKey('outpost:upgrade')
        ->not->toHaveKey('outpost:reload');
});
