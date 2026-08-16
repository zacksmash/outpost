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

    $this->root = sys_get_temp_dir().'/outpost-stop-'.Str::random(10);

    config(['outpost.path' => $this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('stops an instance', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ])),
        processPattern('container', 'stop', 'feature-x-app') => Process::result(''),
    ]);

    $this->artisan('outpost:stop', ['name' => 'feature-x'])
        ->expectsOutputToContain('Stopped [feature-x]')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'stop', 'feature-x-app',
    ]);
});

it('asks which instance when none is given', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ])),
        processPattern('container', 'stop', 'feature-x-app') => Process::result(''),
    ]);

    $this->artisan('outpost:stop')
        ->expectsChoice('Which instance?', 'feature-x', ['feature-x'])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'stop', 'feature-x-app',
    ]);
});

it('treats an already stopped instance as a no-op', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'stopped']],
        ])),
    ]);

    $this->artisan('outpost:stop', ['name' => 'feature-x'])
        ->expectsOutputToContain('already stopped')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'stop');
});

it('explains when the instance has no container to stop', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $this->artisan('outpost:stop', ['name' => 'feature-x'])
        ->expectsOutputToContain('has no container to stop')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'stop');
});

it('explains when there is nothing to stop', function () {
    Process::fake();

    $this->artisan('outpost:stop')
        ->expectsOutputToContain('No instances exist yet.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('surfaces the real error when stopping fails', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ])),
        processPattern('container', 'stop', 'feature-x-app') => Process::result('', 'daemon unavailable', 1),
    ]);

    $this->artisan('outpost:stop', ['name' => 'feature-x'])
        ->expectsOutputToContain('daemon unavailable')
        ->assertFailed();
});

it('explains when Apple container cannot settle a stale exec handle', function () {
    Sleep::fake();

    app(Outposts::class)->save(fakeManifest('feature-x'));

    $error = 'Error: internalError: "failed to stop container" (cause: "exec 6e35c967-dead-beef does not exist in container feature-x-app")';

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ])),
        processPattern('container', 'stop', 'feature-x-app') => Process::result('', $error, 1),
    ]);

    $this->artisan('outpost:stop', ['name' => 'feature-x'])
        ->expectsOutputToContain('Apple container is still settling a recently completed command')
        ->doesntExpectOutputToContain('internalError')
        ->assertFailed();
});
