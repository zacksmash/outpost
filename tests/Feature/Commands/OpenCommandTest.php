<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-open-'.Str::random(10);

    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest('feature-x'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('opens a running instance in the default browser', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('open', 'http://feature-x-app.outpost') => Process::result(''),
    ]);

    $this->artisan('outpost:open', ['name' => 'feature-x'])
        ->expectsOutputToContain('Opened: http://feature-x-app.outpost')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'open', 'http://feature-x-app.outpost',
    ]);
});

it('starts a stopped instance before opening it', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'start', 'feature-x-app') => Process::result(''),
        processPattern('container', 'exec', 'feature-x-app', 'curl').' *' => Process::result(''),
        processPattern('dscacheutil', '-flushcache') => Process::result(''),
        processPattern('open', 'http://feature-x-app.outpost') => Process::result(''),
    ]);

    $this->artisan('outpost:open', ['name' => 'feature-x'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'start', 'feature-x-app',
    ]);
});

it('refuses to open an unknown instance', function () {
    Process::fake();

    $this->artisan('outpost:open', ['name' => 'missing'])
        ->expectsOutputToContain('The [missing] instance does not exist.')
        ->assertFailed();
});

it('opens the mailpit endpoint', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-x', services: ['mailpit']));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('open', 'http://feature-x-app.outpost:8025') => Process::result(''),
    ]);

    $this->artisan('outpost:open', ['name' => 'feature-x', 'endpoint' => 'mailpit'])
        ->expectsOutputToContain('http://feature-x-app.outpost:8025')
        ->assertSuccessful();
});

it('refuses a browser endpoint the instance does not provide', function () {
    app(Outposts::class)->save(fakeManifest(name: 'private', services: [], exposeServices: false));
    Process::fake();

    $this->artisan('outpost:open', ['name' => 'private', 'endpoint' => 'mailpit'])
        ->expectsOutputToContain('does not expose a [mailpit] browser endpoint')
        ->assertFailed();
});
