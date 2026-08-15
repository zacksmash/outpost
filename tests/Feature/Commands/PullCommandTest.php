<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();
});

it('pulls the configured image when it is missing', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result('', 'not found', 1),
        processPattern('container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result('pulled'),
    ]);

    $this->artisan('outpost:pull')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.2.1',
    ]);
});

it('keeps an existing image when refresh is declined', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result('[{}]'),
    ]);

    $this->artisan('outpost:pull')
        ->expectsConfirmation('The [ghcr.io/zacksmash/outpost:0.2.1] image already exists. Pull it again?', 'no')
        ->expectsOutputToContain('outpost:pull --force')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'pull');
});

it('refreshes an existing image when forced', function () {
    Process::fake([
        processPattern('container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result('pulled'),
    ]);

    $this->artisan('outpost:pull', ['--force' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.2.1',
    ]);
});

it('surfaces registry failures with the local build fallback', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result('', 'not found', 1),
        processPattern('container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result('', 'registry unavailable', 1),
    ]);

    $this->artisan('outpost:pull')
        ->expectsOutputToContain('registry unavailable')
        ->expectsOutputToContain('outpost:build')
        ->assertFailed();
});
