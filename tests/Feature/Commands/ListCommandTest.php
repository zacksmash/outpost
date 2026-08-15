<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-list-'.Str::random(10);

    config(['outpost.path' => $this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('shows a friendly empty state', function () {
    Process::fake();

    $this->artisan('outpost:list')
        ->expectsOutputToContain('No instances yet. Create one with [php artisan outpost].')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('emits an empty json collection without consulting the runtime', function () {
    Process::fake();

    $exit = Artisan::call('outpost:list', ['--json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([]);

    Process::assertNothingRan();
});

it('lists every instance with its live state', function () {
    app(Outposts::class)->save(fakeManifest('feature-x', processes: ['queue']));
    app(Outposts::class)->save(fakeManifest('feature-y'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('feature-x')
        ->and($output)->toContain('running')
        ->and($output)->toContain('missing')
        ->and($output)->toContain('http://feature-x-app.outpost')
        ->and($output)->toContain('8.4 / fpm')
        ->and($output)->toContain('mysql, redis')
        ->and($output)->toContain('queue');
});

it('emits machine-readable instance state', function () {
    app(Outposts::class)->save(fakeManifest('feature-x', processes: ['queue']));
    app(Outposts::class)->save(fakeManifest('feature-y'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list', ['--json' => true]);
    $instances = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($instances)->toHaveCount(2)
        ->and($instances[0])->toMatchArray([
            'name' => 'feature-x',
            'container' => 'feature-x-app',
            'url' => 'http://feature-x-app.outpost',
            'php' => '8.4',
            'server' => 'fpm',
            'frontend' => 'build',
            'services' => ['mysql', 'redis'],
            'processes' => ['queue'],
            'state' => 'running',
        ])
        ->and($instances[1]['state'])->toBe('missing')
        ->and($instances[1]['status'])->toBe('degraded');
});

it('reports a running instance that failed provisioning as degraded', function () {
    app(Outposts::class)->save(fakeManifest('failed', status: 'failed'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'failed-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list', ['--json' => true]);
    $instances = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($instances[0]['state'])->toBe('degraded')
        ->and($instances[0]['status'])->toBe('failed');
});

it('fails with the real error when the container daemon is unreachable', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('', 'daemon unavailable', 1),
    ]);

    $this->artisan('outpost:list')
        ->expectsOutputToContain('daemon unavailable')
        ->assertFailed();
});
