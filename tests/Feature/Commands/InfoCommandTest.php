<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-info-'.Str::random(10);
    config(['outpost.path' => $this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('shows runtime details and service connection information', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        server: 'octane',
        octaneServer: 'roadrunner',
        frontend: 'vite',
        database: 'mysql',
        services: ['mysql', 'redis', 'mailpit'],
        exposeServices: true,
    ));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:info', ['name' => 'billing']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('billing')
        ->and($output)->toContain('running')
        ->and($output)->toContain('octane / roadrunner')
        ->and($output)->toContain('4 CPU / 2G')
        ->and($output)->toContain('mysql://outpost:password@billing-app.outpost:3306/outpost')
        ->and($output)->toContain('http://billing-app.outpost:8025');
});

it('provides structured json for agents and scripts', function () {
    app(Outposts::class)->save(fakeManifest(name: 'billing', services: [], exposeServices: false));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $exit = Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($output['name'])->toBe('billing')
        ->and($output['state'])->toBe('missing')
        ->and($output['octane_server'])->toBeNull()
        ->and($output['resources'])->toBe(['cpus' => 4, 'memory' => '2G'])
        ->and($output['endpoints']['application']['url'])->toBe('http://billing-app.outpost')
        ->and($output['expose_services'])->toBeFalse();
});

it('reports a running instance that failed provisioning as degraded', function () {
    app(Outposts::class)->save(fakeManifest(name: 'billing', status: 'failed'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output['state'])->toBe('degraded')
        ->and($output['status'])->toBe('failed');
});
