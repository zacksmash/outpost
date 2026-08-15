<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-reload-'.Str::random(10);

    config(['outpost.path' => $this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('reloads octane workers inside a running instance', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        php: '8.5',
        server: 'octane',
        octaneServer: 'frankenphp',
    ));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => Process::result("Reloading workers...\n"),
    ]);

    $this->artisan('outpost:reload', ['name' => 'feature-x'])
        ->expectsOutputToContain('Reloaded [feature-x]')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'php8.5', 'artisan', 'octane:reload',
    ]);
});

it('refuses to reload a php fpm instance', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-x', server: 'fpm'));
    Process::fake();

    $this->artisan('outpost:reload', ['name' => 'feature-x'])
        ->expectsOutputToContain('does not use Octane')
        ->assertFailed();

    Process::assertNothingRan();
});

it('refuses to reload a stopped instance', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-x', server: 'octane'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $this->artisan('outpost:reload', ['name' => 'feature-x'])
        ->expectsOutputToContain('is not running')
        ->expectsOutputToContain('php artisan outpost:start feature-x')
        ->assertFailed();
});

it('refuses an unknown instance', function () {
    Process::fake();

    $this->artisan('outpost:reload', ['name' => 'missing'])
        ->expectsOutputToContain('The [missing] instance does not exist')
        ->assertFailed();
});
