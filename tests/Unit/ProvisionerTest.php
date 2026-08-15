<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\LifecycleHooks;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\Runtime;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-provision-'.Str::random(10);
    $this->outposts = new Outposts($this->root);
    $this->provisioner = new Provisioner(new Runtime, $this->outposts, app('config'), app(LifecycleHooks::class));

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

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));

    expect(File::get($this->root.'/feature-x/app/.env'))->toContain('APP_NAME=Example');
});

it('refuses to provision without an example environment file', function () {
    Process::fake();

    File::delete($this->root.'/feature-x/app/.env.example');

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));
})->throws(RuntimeException::class, 'no .env.example');

it('preserves an existing .env file', function () {
    Process::fake();

    File::put($this->root.'/feature-x/app/.env', "APP_NAME=Existing\n");

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));

    expect(File::get($this->root.'/feature-x/app/.env'))->toContain('APP_NAME=Existing');
});

it('points the environment at the sandbox mysql service', function () {
    Process::fake();

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'mysql', services: ['mysql']));

    $env = File::get($this->root.'/feature-x/app/.env');

    expect($env)->toContain('DB_CONNECTION=mysql')
        ->and($env)->toContain('DB_HOST=127.0.0.1')
        ->and($env)->toContain('DB_PORT=3306')
        ->and($env)->toContain('DB_DATABASE=outpost')
        ->and($env)->toContain('DB_USERNAME=outpost')
        ->and($env)->toContain('DB_PASSWORD=password')
        ->and($env)->toContain('APP_URL=http://feature-x-app.outpost');
});

it('refuses credentials containing unsafe characters', function () {
    Process::fake();

    config(['outpost.database.password' => "pass\nword"]);

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'mysql', services: ['mysql']));
})->throws(RuntimeException::class, 'must start with a letter or number');

it('writes values containing regex replacement characters literally', function () {
    Process::fake();

    File::put($this->root.'/feature-x/app/.env', "APP_URL=http://localhost\n");

    $manifest = new Manifest(
        name: 'feature-x',
        container: 'feature-x-app',
        url: 'http://feature-x-app.out$1post\\box',
        branch: 'feature/x',
        php: '8.4',
        frontend: 'build',
        exposeServices: false,
        services: [],
        processes: [],
        database: 'sqlite',
        createdAt: CarbonImmutable::parse('2026-08-14T09:00:00+00:00'),
    );

    $this->provisioner->provision($manifest);

    expect(File::get($this->root.'/feature-x/app/.env'))
        ->toContain('APP_URL=http://feature-x-app.out$1post\\box');
});

it('uses the postgres port for pgsql instances', function () {
    Process::fake();

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'pgsql', services: ['pgsql']));

    expect(File::get($this->root.'/feature-x/app/.env'))->toContain('DB_PORT=5432');
});

it('replaces existing keys instead of duplicating them', function () {
    Process::fake();

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'mysql', services: ['mysql']));

    $env = File::get($this->root.'/feature-x/app/.env');

    expect(substr_count($env, 'DB_CONNECTION='))->toBe(1)
        ->and(substr_count($env, 'APP_URL='))->toBe(1)
        ->and($env)->not->toContain('APP_URL=http://localhost');
});

it('creates the sqlite database file and points the environment at it', function () {
    Process::fake();

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));

    expect(File::exists($this->root.'/feature-x/app/database/database.sqlite'))->toBeTrue()
        ->and(File::get($this->root.'/feature-x/app/.env'))->toContain('DB_CONNECTION=sqlite')
        // The .env.example may carry another application's database name, so
        // the sqlite path must be written explicitly.
        ->and(File::get($this->root.'/feature-x/app/.env'))->toContain('DB_DATABASE=/app/database/database.sqlite');
});

it('leaves the database environment alone when the instance does not run the database service', function () {
    Process::fake();

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'mysql', services: ['redis']));

    expect(File::get($this->root.'/feature-x/app/.env'))->not->toContain('DB_HOST=127.0.0.1');
});

it('points the environment at redis and mailpit when used', function () {
    Process::fake();

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: ['redis', 'mailpit']));

    $env = File::get($this->root.'/feature-x/app/.env');

    expect($env)->toContain('REDIS_HOST=127.0.0.1')
        ->and($env)->toContain('REDIS_PORT=6379')
        ->and($env)->toContain('REDIS_PASSWORD=password')
        ->and($env)->toContain('MAIL_MAILER=smtp')
        ->and($env)->toContain('MAIL_HOST=127.0.0.1')
        ->and($env)->toContain('MAIL_PORT=1025');
});

it('does not require a redis password when service access is private', function () {
    Process::fake();

    $this->provisioner->provision(fakeManifest(
        name: 'feature-x',
        database: 'sqlite',
        services: ['redis'],
        exposeServices: false,
    ));

    expect(File::get($this->root.'/feature-x/app/.env'))->not->toContain('REDIS_PASSWORD=');
});

it('runs the container steps in order, pinned to the instance php version', function () {
    Process::fake();

    $steps = [];

    $this->provisioner->provision(
        fakeManifest(name: 'feature-x', php: '8.5', database: 'sqlite', services: []),
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
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app', 'feature-x-app',
        'php8.5', '/usr/local/bin/composer', 'install', '--no-interaction', '--prefer-dist',
    ], 1);

    Process::assertRanTimes(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'php8.5', 'artisan', 'migrate', '--force',
    ], 1);

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('db:seed', $process->command, true));
});

it('recovers a surviving worktree without replacing its key and refreshes runtime-dependent assets', function () {
    File::put($this->root.'/feature-x/app/.env', "APP_KEY=base64:existing\nAPP_URL=http://localhost\n");
    File::put($this->root.'/feature-x/app/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));

    Process::fake();
    $steps = [];

    $this->provisioner->recover(
        fakeManifest(name: 'feature-x', php: '8.5', database: 'sqlite', services: []),
        onStep: function (string $step) use (&$steps) {
            $steps[] = $step;
        },
    );

    expect($steps)->toBe([
        'Refreshing the environment configuration',
        'Installing composer dependencies',
        'Installing npm dependencies',
        'Building the front-end assets',
        'Running the database migrations',
    ])->and(File::get($this->root.'/feature-x/app/.env'))->toContain('APP_KEY=base64:existing');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'php8.5', 'artisan', 'migrate', '--force',
    ]);
    Process::assertDidntRun(fn (PendingProcess $process) => in_array('key:generate', $process->command, true));
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'npm', 'install', '--no-fund', '--no-audit',
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'npm', 'run', 'build',
    ]);
});

it('removes environment values managed by services disabled during recovery', function () {
    File::put($this->root.'/feature-x/app/.env', implode("\n", [
        'APP_KEY=base64:existing',
        'APP_URL=http://feature-x-app.outpost',
        'DB_CONNECTION=mysql',
        'DB_HOST=127.0.0.1',
        'DB_PORT=3306',
        'DB_DATABASE=outpost',
        'DB_USERNAME=outpost',
        'DB_PASSWORD=password',
        'REDIS_HOST=127.0.0.1',
        'REDIS_PORT=6379',
        'REDIS_PASSWORD=password',
        'MAIL_MAILER=smtp',
        'MAIL_HOST=127.0.0.1',
        'MAIL_PORT=1025',
        'CUSTOM_VALUE=preserved',
    ])."\n");

    Process::fake();

    $previous = fakeManifest(
        name: 'feature-x',
        database: 'mysql',
        services: ['mysql', 'redis', 'mailpit'],
        exposeServices: true,
    );
    $current = fakeManifest(
        name: 'feature-x',
        frontend: 'none',
        database: null,
        services: [],
        url: 'https://feature-x-app.outpost',
        exposeServices: false,
    );

    $this->provisioner->recover($current, previous: $previous);

    $environment = File::get($this->root.'/feature-x/app/.env');

    expect($environment)->toContain('APP_KEY=base64:existing')
        ->and($environment)->toContain('APP_URL=https://feature-x-app.outpost')
        ->and($environment)->toContain('CUSTOM_VALUE=preserved')
        ->and($environment)->not->toContain('DB_CONNECTION=')
        ->and($environment)->not->toContain('REDIS_HOST=')
        ->and($environment)->not->toContain('REDIS_PASSWORD=')
        ->and($environment)->not->toContain('MAIL_MAILER=');
});

it('installs npm dependencies and builds assets when the app has a build script', function () {
    Process::fake();

    File::put($this->root.'/feature-x/app/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));

    $steps = [];

    $this->provisioner->provision(
        fakeManifest(name: 'feature-x', database: 'sqlite', services: []),
        onStep: function (string $step) use (&$steps) {
            $steps[] = $step;
        },
    );

    expect($steps)->toContain('Installing npm dependencies')
        ->and($steps)->toContain('Building the front-end assets');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'npm', 'install', '--no-fund', '--no-audit',
    ]);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'npm', 'run', 'build',
    ]);
});

it('uses npm ci when the application has a lock file', function () {
    Process::fake();

    File::put($this->root.'/feature-x/app/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));
    File::put($this->root.'/feature-x/app/package-lock.json', json_encode([
        'lockfileVersion' => 3,
    ], JSON_THROW_ON_ERROR));

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'npm', 'ci', '--no-fund', '--no-audit',
    ]);
    Process::assertDidntRun(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'npm', 'install', '--no-fund', '--no-audit',
    ]);
});

it('removes a stale vite hot file before building assets', function () {
    Process::fake();

    File::ensureDirectoryExists($this->root.'/feature-x/app/public');
    File::put($this->root.'/feature-x/app/public/hot', 'http://localhost:5173');
    File::put($this->root.'/feature-x/app/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));

    expect(File::exists($this->root.'/feature-x/app/public/hot'))->toBeFalse();
});

it('skips the front-end build without a build script', function () {
    Process::fake();

    File::put($this->root.'/feature-x/app/package.json', json_encode([
        'scripts' => ['dev' => 'vite'],
    ], JSON_THROW_ON_ERROR));

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('npm', $process->command, true));
});

it('skips frontend installation when frontend support is disabled', function () {
    Process::fake();

    File::put($this->root.'/feature-x/app/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));

    $this->provisioner->provision(fakeManifest(
        name: 'feature-x',
        database: 'sqlite',
        services: [],
        frontend: 'none',
    ));

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('npm', $process->command, true));
});

it('seeds the database only when asked', function () {
    Process::fake();

    $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []), seed: true);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'php8.4', 'artisan', 'db:seed', '--force',
    ]);
});

it('stops at the first failing step and names it', function () {
    Process::fake([
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'php8.4', '/usr/local/bin/composer').' *' => Process::result('', 'could not resolve host', 1),
    ]);

    try {
        $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));

        $this->fail('A provisioning failure should have been thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Installing composer dependencies failed.')
            ->and($e->getMessage())->toContain('could not resolve host');
    }

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('migrate', $process->command, true));
});

it('reports stdout and stderr together when a provisioning command fails', function () {
    Process::fake([
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'php8.4', '/usr/local/bin/composer').' *' => Process::result(
            'Class "Zacksmash\\Outpost\\OutpostServiceProvider" not found',
            'Composer plugins have been disabled',
            1,
        ),
    ]);

    try {
        $this->provisioner->provision(fakeManifest(name: 'feature-x', database: 'sqlite', services: []));

        $this->fail('A provisioning failure should have been thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('Class "Zacksmash\\Outpost\\OutpostServiceProvider" not found')
            ->toContain('Composer plugins have been disabled');
    }
});
