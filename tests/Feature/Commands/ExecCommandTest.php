<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-exec-'.Str::random(10);

    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest('feature-x'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('runs argument tokens without a shell and passes the exit code through', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => Process::result('', '', 3),
    ]);

    $exit = Artisan::call('outpost:exec feature-x -- php artisan test --filter=Feature');

    expect($exit)->toBe(3);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'php', 'artisan', 'test', '--filter=Feature',
    ]);
});

it('runs as root only when explicitly requested', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => Process::result(''),
    ]);

    $this->artisan('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['apt-get', 'update'],
        '--root' => true,
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/root', '--user', 'root', '--workdir', '/app',
        'feature-x-app', 'apt-get', 'update',
    ]);
});

it('requires a command', function () {
    Process::fake();

    $this->artisan('outpost:exec', ['name' => 'feature-x'])
        ->expectsOutputToContain('Provide the command to run')
        ->assertFailed();

    Process::assertNothingRan();
});

it('refuses when the instance is not running', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $this->artisan('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['php', 'artisan', 'test'],
    ])
        ->expectsOutputToContain('is not running')
        ->expectsOutputToContain('php artisan outpost:start feature-x')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('refuses an unknown instance', function () {
    Process::fake();

    $this->artisan('outpost:exec', [
        'name' => 'missing',
        'arguments' => ['php', '-v'],
    ])
        ->expectsOutputToContain('The [missing] instance does not exist.')
        ->assertFailed();
});
