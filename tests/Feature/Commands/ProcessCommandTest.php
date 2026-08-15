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

    $this->root = sys_get_temp_dir().'/outpost-process-'.Str::random(10);
    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        processes: ['queue', 'scheduler'],
    ));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('shows the state of every configured application process', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-queue') => Process::result('outpost-queue RUNNING pid 41, uptime 0:02:10'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-scheduler') => Process::result('outpost-scheduler BACKOFF Exited too quickly', '', 3),
    ]);

    $exit = Artisan::call('outpost:process', ['name' => 'billing']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('queue')
        ->and($output)->toContain('running')
        ->and($output)->toContain('scheduler')
        ->and($output)->toContain('backoff');
});

it('provides structured process state for agents', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-queue') => Process::result('outpost-queue RUNNING pid 41, uptime 0:02:10'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-scheduler') => Process::result('outpost-scheduler STOPPED Not started', '', 3),
    ]);

    $exit = Artisan::call('outpost:process', ['name' => 'billing', '--json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'name' => 'billing',
            'processes' => [
                ['name' => 'queue', 'state' => 'running', 'details' => 'pid 41, uptime 0:02:10'],
                ['name' => 'scheduler', 'state' => 'stopped', 'details' => 'Not started'],
            ],
        ]);
});

it('can inspect one configured process', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-queue') => Process::result('outpost-queue RUNNING pid 41, uptime 0:02:10'),
    ]);

    $this->artisan('outpost:process', ['name' => 'billing', 'process' => 'queue'])
        ->expectsOutputToContain('queue')
        ->doesntExpectOutputToContain('scheduler')
        ->assertSuccessful();
});

it('restarts one configured process and reports its new state', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'restart', 'outpost-queue') => Process::result("outpost-queue: stopped\noutpost-queue: started"),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-queue') => Process::result('outpost-queue RUNNING pid 52, uptime 0:00:01'),
    ]);

    $this->artisan('outpost:process', [
        'name' => 'billing',
        'process' => 'queue',
        '--restart' => true,
    ])
        ->expectsOutputToContain('Restarted [queue] in [billing].')
        ->expectsOutputToContain('running')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/root',
        '--env', 'COMPOSER_CACHE_DIR=/root/.composer/cache', '--env', 'NPM_CONFIG_CACHE=/root/.npm',
        '--user', 'root', '--workdir', '/app',
        'billing-app', 'supervisorctl', 'restart', 'outpost-queue',
    ]);
});

it('requires a process name when restarting', function () {
    Process::fake();

    $this->artisan('outpost:process', ['name' => 'billing', '--restart' => true])
        ->expectsOutputToContain('Choose a configured process to restart')
        ->assertFailed();

    Process::assertNothingRan();
});

it('refuses processes that are not recorded for the instance', function () {
    Process::fake();

    $this->artisan('outpost:process', ['name' => 'billing', 'process' => 'nginx', '--restart' => true])
        ->expectsOutputToContain('The [nginx] process is not configured for [billing].')
        ->assertFailed();

    Process::assertNothingRan();
});

it('refuses process inspection when the instance is not running', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $this->artisan('outpost:process', ['name' => 'billing'])
        ->expectsOutputToContain('is not running')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('refuses process inspection until provisioning is ready', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        processes: ['queue'],
        status: 'failed',
    ));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->artisan('outpost:process', ['name' => 'billing'])
        ->expectsOutputToContain('provisioning status is [failed]')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('reports when an instance has no configured application processes', function () {
    app(Outposts::class)->save(fakeManifest(name: 'billing', processes: []));
    Process::fake();

    $this->artisan('outpost:process', ['name' => 'billing'])
        ->expectsOutputToContain('has no configured application processes')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('surfaces supervisor failures without hiding their output', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-queue') => Process::result('', 'unix:///var/run/supervisor.sock refused connection', 1),
    ]);

    $this->artisan('outpost:process', ['name' => 'billing', 'process' => 'queue'])
        ->expectsOutputToContain('refused connection')
        ->assertFailed();
});
