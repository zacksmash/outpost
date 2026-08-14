<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();
});

it('builds the base image from the package stubs', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result('', 'not found', 1),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:build')->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) {
        $context = end($process->command);

        return array_slice($process->command, 0, 6) === [
            'container', 'build', '--dns', '1.1.1.1', '--tag', 'outpost-base',
        ] && is_file($context.'/Dockerfile');
    });
});

it('passes the configured database credentials as build arguments', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result('', 'not found', 1),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:build')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => count(array_intersect([
        'DB_DATABASE=outpost',
        'DB_USERNAME=outpost',
        'DB_PASSWORD=password',
    ], $process->command)) === 3);
});

it('keeps an existing image when the rebuild is declined', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result('[{"reference":"outpost-base"}]'),
    ]);

    $this->artisan('outpost:build')
        ->expectsConfirmation('The [outpost-base] image already exists. Rebuild it?', 'no')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'build');
});

it('rebuilds an existing image when confirmed', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result('[{"reference":"outpost-base"}]'),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:build')
        ->expectsConfirmation('The [outpost-base] image already exists. Rebuild it?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'build');
});

it('rebuilds an existing image without asking when forced', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result('[{"reference":"outpost-base"}]'),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:build', ['--force' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'build');
});

it('fails with the real error when the build breaks', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result('', 'not found', 1),
        processPattern('container', 'build').' *' => Process::result('', 'dns lookup failed', 1),
    ]);

    $this->artisan('outpost:build')
        ->expectsOutputToContain('dns lookup failed')
        ->assertFailed();
});
