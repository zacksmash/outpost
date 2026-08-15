<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();
});

it('builds the base image from the package stubs', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.1') => Process::result('', 'not found', 1),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:build')->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) {
        $context = end($process->command);

        return array_slice($process->command, 0, 6) === [
            'container', 'build', '--dns', '1.1.1.1', '--tag', 'ghcr.io/zacksmash/outpost:0.1.1',
        ] && is_file($context.'/Dockerfile')
            && is_file($context.'/wait-for-app.sh')
            && is_file($context.'/vite.sh');
    });
});

it('passes the configured database credentials as build arguments', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.1') => Process::result('', 'not found', 1),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:build')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => count(array_intersect([
        'DB_DATABASE=outpost',
        'DB_USERNAME=outpost',
        'DB_PASSWORD=password',
        'PHP_VERSIONS=8.4 8.5',
    ], $process->command)) === 4);
});

it('refuses malformed php versions', function () {
    Process::fake();

    config(['outpost.php' => ['8.4', 'eight-five']]);

    $this->artisan('outpost:build')
        ->expectsOutputToContain('outpost.php')
        ->assertFailed();

    Process::assertNothingRan();
});

it('keeps an existing image when the rebuild is declined', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.1') => Process::result('[{"reference":"ghcr.io/zacksmash/outpost:0.1.1"}]'),
    ]);

    $this->artisan('outpost:build')
        ->expectsConfirmation('The [ghcr.io/zacksmash/outpost:0.1.1] image already exists. Rebuild it?', 'no')
        ->expectsOutputToContain('outpost:build --force')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'build');
});

it('rebuilds an existing image when confirmed', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.1') => Process::result('[{"reference":"ghcr.io/zacksmash/outpost:0.1.1"}]'),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:build')
        ->expectsConfirmation('The [ghcr.io/zacksmash/outpost:0.1.1] image already exists. Rebuild it?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'build');
});

it('rebuilds an existing image without asking when forced', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.1') => Process::result('[{"reference":"ghcr.io/zacksmash/outpost:0.1.1"}]'),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:build', ['--force' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'build');
});

it('refuses credentials containing shell or sql metacharacters', function (string $key, string $value) {
    Process::fake();

    config(["outpost.database.{$key}" => $value]);

    $this->artisan('outpost:build')
        ->expectsOutputToContain("outpost.database.{$key}")
        ->assertFailed();

    Process::assertNothingRan();
})->with([
    'quoted password' => ['password', "pass'word"],
    'backtick database' => ['database', 'out`post'],
    'dollar username' => ['username', 'out$post'],
    'empty password' => ['password', ''],
    'leading dash database' => ['database', '-outpost'],
]);

it('refuses an empty php version list', function () {
    Process::fake();

    config(['outpost.php' => []]);

    $this->artisan('outpost:build')
        ->expectsOutputToContain('at least one PHP version')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with the real error when the build breaks', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.1') => Process::result('', 'not found', 1),
        processPattern('container', 'build').' *' => Process::result('', 'dns lookup failed', 1),
    ]);

    $this->artisan('outpost:build')
        ->expectsOutputToContain('dns lookup failed')
        ->assertFailed();
});
