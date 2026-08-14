<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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

    Process::fake();

    $this->artisan('outpost:stop', ['name' => 'feature-x'])
        ->expectsOutputToContain('Stopped [feature-x]')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'stop', 'feature-x-app',
    ]);
});

it('asks which instance when none is given', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake();

    $this->artisan('outpost:stop')
        ->expectsChoice('Which instance?', 'feature-x', ['feature-x'])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'stop', 'feature-x-app',
    ]);
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
        processPattern('container', 'stop', 'feature-x-app') => Process::result('', 'daemon unavailable', 1),
    ]);

    $this->artisan('outpost:stop', ['name' => 'feature-x'])
        ->expectsOutputToContain('daemon unavailable')
        ->assertFailed();
});
