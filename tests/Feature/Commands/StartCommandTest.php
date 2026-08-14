<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-start-'.Str::random(10);

    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest('feature-x'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('starts a stopped instance and flushes the dns cache', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'start', 'feature-x-app') => Process::result(''),
        processPattern('container', 'exec', 'feature-x-app', 'curl').' *' => Process::result(''),
        processPattern('dscacheutil', '-flushcache') => Process::result(''),
    ]);

    $this->artisan('outpost:start', ['name' => 'feature-x'])
        ->expectsOutputToContain('Started: http://feature-x-app.outpost')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'start', 'feature-x-app',
    ]);
});

it('leaves an already running instance alone', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->artisan('outpost:start', ['name' => 'feature-x'])
        ->expectsOutputToContain('already running')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'start');
});

it('points at the logs when the instance starts but never answers', function () {
    Sleep::fake();

    config(['outpost.timeout' => 2]);

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
        processPattern('container', 'start', 'feature-x-app') => Process::result(''),
        processPattern('container', 'exec', 'feature-x-app', 'curl').' *' => Process::result('', 'refused', 7),
    ]);

    $this->artisan('outpost:start', ['name' => 'feature-x'])
        ->expectsOutputToContain('did not answer HTTP within 2 seconds')
        ->expectsOutputToContain('php artisan outpost:logs feature-x')
        ->assertFailed();
});

it('refuses an unknown instance', function () {
    Process::fake();

    $this->artisan('outpost:start', ['name' => 'missing'])
        ->expectsOutputToContain('The [missing] instance does not exist.')
        ->assertFailed();
});
