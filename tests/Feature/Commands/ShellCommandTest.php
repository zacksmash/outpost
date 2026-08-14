<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-shell-'.Str::random(10);

    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest('feature-x'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('opens a shell inside a running instance', function () {
    $tty = Symfony\Component\Process\Process::isTtySupported();

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec', '-i').' *' => Process::result(''),
    ]);

    $this->artisan('outpost:shell', ['name' => 'feature-x'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '-i', ...($tty ? ['-t'] : []), 'feature-x-app', 'bash',
    ]);
});

it('refuses when the instance is not running', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $this->artisan('outpost:shell', ['name' => 'feature-x'])
        ->expectsOutputToContain('is not running')
        ->expectsOutputToContain('php artisan outpost:start feature-x')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('bash', $process->command, true));
});

it('refuses an unknown instance', function () {
    Process::fake();

    $this->artisan('outpost:shell', ['name' => 'missing'])
        ->expectsOutputToContain('The [missing] instance does not exist.')
        ->assertFailed();
});
