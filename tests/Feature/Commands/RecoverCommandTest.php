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

    $this->root = sys_get_temp_dir().'/outpost-recover-'.Str::random(10);

    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest('feature-x'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function fakeRecovery(array $overrides = []): void
{
    Process::fake($overrides + [
        processPattern('pgrep', '-f', 'container exec .*[[:space:]]feature-x-app[[:space:]]') => Process::sequence()
            ->push(Process::result("123\n456\n"))
            ->push(Process::result('', '', 1)),
        processPattern('kill', '-TERM', '123', '456') => Process::result(''),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::sequence()
            ->push(Process::result(json_encode([
                ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
            ], JSON_THROW_ON_ERROR)))
            ->push(Process::result(json_encode([
                ['id' => 'feature-x-app', 'status' => ['state' => 'stopped']],
            ], JSON_THROW_ON_ERROR))),
        processPattern('container', 'stop', 'feature-x-app') => Process::result(''),
        processPattern('container', 'start', 'feature-x-app') => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'curl').' *' => Process::result(''),
        processPattern('dscacheutil', '-flushcache') => Process::result(''),
    ]);
}

it('refuses non-interactive recovery without the explicit force option', function () {
    fakeRecovery();

    $this->artisan('outpost:recover', ['name' => 'feature-x', '--no-interaction' => true])
        ->expectsOutputToContain('Refusing to recover non-interactively without --force.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('recovers nothing when declined', function () {
    fakeRecovery();

    $this->artisan('outpost:recover', ['name' => 'feature-x'])
        ->expectsConfirmation('Recover the [feature-x] instance? Host [container exec] clients attached to it will be killed and its container restarted.', 'no')
        ->expectsOutputToContain('Nothing recovered.')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('kills stale exec clients, stops the container, and starts it again', function () {
    Sleep::fake();

    fakeRecovery();

    $this->artisan('outpost:recover', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('Killed 2 stale exec clients attached to [feature-x-app].')
        ->expectsOutputToContain('Started [feature-x]')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['kill', '-TERM', '123', '456']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['container', 'stop', 'feature-x-app']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['container', 'start', 'feature-x-app']);
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === '-KILL');
});

it('forcefully kills exec clients that survive the graceful signal', function () {
    Sleep::fake();

    fakeRecovery([
        processPattern('pgrep', '-f', 'container exec .*[[:space:]]feature-x-app[[:space:]]') => Process::sequence()
            ->push(Process::result("123\n"))
            ->push(Process::result("123\n999\n")),
        processPattern('kill', '-TERM', '123') => Process::result(''),
        processPattern('kill', '-KILL', '123') => Process::result(''),
    ]);

    $this->artisan('outpost:recover', ['name' => 'feature-x', '--force' => true])
        ->assertSuccessful();

    // Client 999 attached during the grace window and was never signaled
    // gracefully, so escalation must stay scoped to the original clients.
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['kill', '-KILL', '123']);
    Process::assertDidntRun(fn (PendingProcess $process) => in_array('999', $process->command, true));
});

it('skips stopping when the container is not running', function () {
    Sleep::fake();

    fakeRecovery([
        processPattern('pgrep', '-f', 'container exec .*[[:space:]]feature-x-app[[:space:]]') => Process::result('', '', 1),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->artisan('outpost:recover', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('Started [feature-x]')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'stop');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'kill');
});

it('reports a failed stop instead of restarting a wedged container', function () {
    Sleep::fake();

    fakeRecovery([
        processPattern('pgrep', '-f', 'container exec .*[[:space:]]feature-x-app[[:space:]]') => Process::result('', '', 1),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'stop', 'feature-x-app') => Process::result('', 'failed to stop container: exec abc does not exist in container', 1),
    ]);

    $this->artisan('outpost:recover', ['name' => 'feature-x', '--force' => true])
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'start');
});
