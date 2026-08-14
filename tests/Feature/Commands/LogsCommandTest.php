<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-logs-'.Str::random(10);

    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest('feature-x'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('shows the instance logs', function () {
    Process::fake([
        processPattern('container', 'logs', 'feature-x-app') => Process::result("nginx started\n"),
    ]);

    $this->artisan('outpost:logs', ['name' => 'feature-x'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'logs', 'feature-x-app',
    ]);
});

it('streams the logs when following', function () {
    Process::fake([
        processPattern('container', 'logs', '--follow', 'feature-x-app') => Process::result(''),
    ]);

    $this->artisan('outpost:logs', ['name' => 'feature-x', '--follow' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'logs', '--follow', 'feature-x-app',
    ]);
});

it('surfaces the real error when the logs cannot be fetched', function () {
    Process::fake([
        processPattern('container', 'logs', 'feature-x-app') => Process::result('', 'no such container', 1),
    ]);

    $this->artisan('outpost:logs', ['name' => 'feature-x'])
        ->expectsOutputToContain('no such container')
        ->assertFailed();
});
