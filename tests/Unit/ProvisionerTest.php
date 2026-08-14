<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\Runtime;

function provisionManifest(array $services, ?string $database, string $php = '8.4'): Manifest
{
    return new Manifest(
        name: 'feature-x',
        container: 'feature-x-app',
        url: 'http://feature-x-app.outpost',
        branch: 'feature/x',
        php: $php,
        services: $services,
        deferred: [],
        database: $database,
        createdAt: CarbonImmutable::parse('2026-08-14T09:00:00+00:00'),
    );
}

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-provision-'.Str::random(10);
    $this->outposts = new Outposts($this->root);
    $this->provisioner = new Provisioner(new Runtime, $this->outposts);

    File::ensureDirectoryExists($this->root.'/feature-x/app');
    File::put($this->root.'/feature-x/app/.env.example', implode("\n", [
        'APP_NAME=Example',
        'APP_URL=http://localhost',
        'DB_CONNECTION=sqlite',
    ])."\n");
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('seeds .env from .env.example when missing', function () {
    Process::fake();

    $this->provisioner->provision(provisionManifest([], 'sqlite'));

    expect(File::get($this->root.'/feature-x/app/.env'))->toContain('APP_NAME=Example');
});

it('refuses to provision without an example environment file', function () {
    Process::fake();

    File::delete($this->root.'/feature-x/app/.env.example');

    $this->provisioner->provision(provisionManifest([], 'sqlite'));
})->throws(RuntimeException::class, 'no .env.example');

it('preserves an existing .env file', function () {
    Process::fake();

    File::put($this->root.'/feature-x/app/.env', "APP_NAME=Existing\n");

    $this->provisioner->provision(provisionManifest([], 'sqlite'));

    expect(File::get($this->root.'/feature-x/app/.env'))->toContain('APP_NAME=Existing');
});

it('points the environment at the sandbox mysql service', function () {
    Process::fake();

    $this->provisioner->provision(provisionManifest(['mysql'], 'mysql'));

    $env = File::get($this->root.'/feature-x/app/.env');

    expect($env)->toContain('DB_CONNECTION=mysql')
        ->and($env)->toContain('DB_HOST=127.0.0.1')
        ->and($env)->toContain('DB_PORT=3306')
        ->and($env)->toContain('DB_DATABASE=outpost')
        ->and($env)->toContain('DB_USERNAME=outpost')
        ->and($env)->toContain('DB_PASSWORD=password')
        ->and($env)->toContain('APP_URL=http://feature-x-app.outpost');
});

it('uses the postgres port for pgsql instances', function () {
    Process::fake();

    $this->provisioner->provision(provisionManifest(['pgsql'], 'pgsql'));

    expect(File::get($this->root.'/feature-x/app/.env'))->toContain('DB_PORT=5432');
});

it('replaces existing keys instead of duplicating them', function () {
    Process::fake();

    $this->provisioner->provision(provisionManifest(['mysql'], 'mysql'));

    $env = File::get($this->root.'/feature-x/app/.env');

    expect(substr_count($env, 'DB_CONNECTION='))->toBe(1)
        ->and(substr_count($env, 'APP_URL='))->toBe(1)
        ->and($env)->not->toContain('APP_URL=http://localhost');
});

it('creates the sqlite database file', function () {
    Process::fake();

    $this->provisioner->provision(provisionManifest([], 'sqlite'));

    expect(File::exists($this->root.'/feature-x/app/database/database.sqlite'))->toBeTrue()
        ->and(File::get($this->root.'/feature-x/app/.env'))->toContain('DB_CONNECTION=sqlite');
});

it('points the environment at redis and mailpit when used', function () {
    Process::fake();

    $this->provisioner->provision(provisionManifest(['redis', 'mailpit'], 'sqlite'));

    $env = File::get($this->root.'/feature-x/app/.env');

    expect($env)->toContain('REDIS_HOST=127.0.0.1')
        ->and($env)->toContain('REDIS_PORT=6379')
        ->and($env)->toContain('MAIL_MAILER=smtp')
        ->and($env)->toContain('MAIL_HOST=127.0.0.1')
        ->and($env)->toContain('MAIL_PORT=1025');
});

it('runs the container steps in order, pinned to the instance php version', function () {
    Process::fake();

    $steps = [];

    $this->provisioner->provision(
        provisionManifest([], 'sqlite', '8.5'),
        onStep: function (string $step) use (&$steps) {
            $steps[] = $step;
        },
    );

    expect($steps)->toBe([
        'Preparing the environment file',
        'Installing composer dependencies',
        'Generating the application key',
        'Linking the storage directory',
        'Running the database migrations',
    ]);

    Process::assertRanTimes(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', 'feature-x-app',
        'php8.5', '/usr/local/bin/composer', 'install', '--no-interaction', '--prefer-dist',
    ], 1);

    Process::assertRanTimes(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', 'feature-x-app', 'php8.5', 'artisan', 'migrate', '--force',
    ], 1);

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('db:seed', $process->command, true));
});

it('seeds the database only when asked', function () {
    Process::fake();

    $this->provisioner->provision(provisionManifest([], 'sqlite'), seed: true);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', 'feature-x-app', 'php8.4', 'artisan', 'db:seed', '--force',
    ]);
});

it('stops at the first failing step and names it', function () {
    Process::fake([
        processPattern('container', 'exec', 'feature-x-app', 'php8.4', '/usr/local/bin/composer').' *' => Process::result('', 'could not resolve host', 1),
    ]);

    try {
        $this->provisioner->provision(provisionManifest([], 'sqlite'));

        $this->fail('A provisioning failure should have been thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Installing composer dependencies failed.')
            ->and($e->getMessage())->toContain('could not resolve host');
    }

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('migrate', $process->command, true));
});
