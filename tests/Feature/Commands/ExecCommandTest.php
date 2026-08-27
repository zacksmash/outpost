<?php

declare(strict_types=1);

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
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
        'container', 'exec', '--env', 'HOME=/root',
        '--env', 'COMPOSER_CACHE_DIR=/root/.composer/cache', '--env', 'NPM_CONFIG_CACHE=/root/.npm',
        '--user', 'root', '--workdir', '/app',
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

it('enforces the requested timeout inside the container with a host backstop', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => Process::result(''),
    ]);

    $this->artisan('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['php', 'artisan', 'test'],
        '--timeout' => '30',
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'timeout', '--kill-after=10', '30', 'php', 'artisan', 'test',
    ] && $process->timeout === 55);
});

it('reports a timeout when the in-container command is killed at the deadline', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => Process::result('', '', 124),
    ]);

    $exit = Artisan::call('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['php', 'artisan', 'test'],
        '--timeout' => '30',
    ]);

    expect($exit)->toBe(124)
        ->and(Artisan::output())->toContain('killed after 30 seconds');
});

it('treats a forced kill after the deadline as the same timeout outcome', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => Process::result('', '', 137),
    ]);

    $exit = Artisan::call('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['php', 'artisan', 'test'],
        '--timeout' => '30',
    ]);

    expect($exit)->toBe(124)
        ->and(Artisan::output())->toContain('killed after 30 seconds');
});

it('passes an inner 137 through untouched when no timeout was requested', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => Process::result('', '', 137),
    ]);

    $exit = Artisan::call('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['php', 'artisan', 'test'],
    ]);

    expect($exit)->toBe(137)
        ->and(Artisan::output())->not->toContain('killed after');
});

it('runs without any timeout by default', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => Process::result(''),
    ]);

    $this->artisan('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['composer', 'install'],
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->timeout === null
        && ($process->command[1] ?? null) === 'exec');
});

it('rejects a timeout that is not a positive whole number of seconds', function () {
    Process::fake();

    $this->artisan('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['php', 'artisan', 'test'],
        '--timeout' => 'soon',
    ])
        ->expectsOutputToContain('The timeout must be a positive whole number of seconds.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('exits with the timeout code and recovery guidance when the command times out', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *' => function () {
            $process = new SymfonyProcess(['container', 'exec']);
            $process->setTimeout(2);

            throw new ProcessTimedOutException(
                new SymfonyTimedOutException($process, SymfonyTimedOutException::TYPE_GENERAL),
                new FakeProcessResult,
            );
        },
    ]);

    $exit = Artisan::call('outpost:exec', [
        'name' => 'feature-x',
        'arguments' => ['php', 'artisan', 'test'],
        '--timeout' => '2',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(124)
        ->and($output)->toContain('killed after 2 seconds')
        ->and($output)->toContain('php artisan outpost:recover feature-x --force');
});
