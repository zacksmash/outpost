<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\PathRepositories;
use Zacksmash\Outpost\Runtime;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-upgrade-'.Str::random(10);
    $this->worktree = $this->root.'/feature-x/app';
    $this->runtime = $this->root.'/feature-x/runtime';
    $this->git = $this->root.'/git';
    $this->currentDigest = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $this->oldDigest = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    config(['outpost.path' => $this->root]);

    File::ensureDirectoryExists($this->worktree);
    File::ensureDirectoryExists($this->runtime);
    File::ensureDirectoryExists($this->git);
    File::put($this->worktree.'/.env', "APP_KEY=base64:existing\nAPP_URL=http://localhost\n");
    File::put($this->worktree.'/composer.json', '{}');
    File::put($this->worktree.'/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));
    File::put($this->worktree.'/package-lock.json', json_encode([
        'lockfileVersion' => 3,
    ], JSON_THROW_ON_ERROR));
    File::put($this->runtime.'/nginx.conf', 'nginx');
    File::put($this->runtime.'/supervisord.conf', 'supervisor');

    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        image: 'ghcr.io/zacksmash/outpost:0.1.1',
        imageDigest: $this->oldDigest,
    ));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function fakeUpgradeProcesses(string $root, string $digest, array $overrides = [], array $containers = [
    ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
]): void
{
    Process::fake($overrides + [
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect($digest)),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode($containers, JSON_THROW_ON_ERROR)),
        processPattern('git', '-C', $root.'/feature-x/app', 'status', '--short') => Process::result(''),
        processPattern('git', 'rev-parse', '--path-format=absolute', '--git-common-dir') => Process::result($root."/git\n"),
        processPattern('id', '-u') => Process::result("501\n"),
        processPattern('id', '-g') => Process::result("20\n"),
        processPattern('container', 'stop').' *' => Process::result(''),
        processPattern('container', 'delete').' *' => Process::result(''),
        processPattern('container', 'run').' *' => Process::result(''),
        processPattern('container', 'exec').' *' => Process::result(''),
        processPattern('dscacheutil', '-flushcache') => Process::result(''),
    ]);
}

it('upgrades only the container while preserving and reprovisioning the worktree', function () {
    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x'])
        ->expectsOutputToContain('resets its container-local database and service data')
        ->expectsOutputToContain('branch [feature/billing] are preserved')
        ->expectsOutputToContain('Upgraded [feature-x]')
        ->assertSuccessful();

    $manifest = app(Outposts::class)->find('feature-x');

    expect($manifest?->image)->toBe(Runtime::PUBLISHED_IMAGE)
        ->and($manifest?->imageDigest)->toBe($this->currentDigest)
        ->and($manifest?->status)->toBe('ready')
        ->and(File::get($this->worktree.'/.env'))->toContain('APP_KEY=base64:existing');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'stop', 'feature-x-app',
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'delete', 'feature-x-app',
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'run', '--detach', '--name', 'feature-x-app', '--dns', '1.1.1.1',
        '--cpus', '4', '--memory', '2G', '--env', 'OUTPOST_UID=501', '--env', 'OUTPOST_GID=20',
        '--env', 'COMPOSER_CACHE_DIR=/var/cache/outpost/composer',
        '--env', 'NPM_CONFIG_CACHE=/var/cache/outpost/npm',
        '--volume', $this->worktree.':/app',
        '--volume', $this->runtime.':/etc/outpost:ro',
        '--volume', $this->git.':'.$this->git.':ro',
        '--volume', $this->root.'/.cache/composer:/var/cache/outpost/composer',
        '--volume', $this->root.'/.cache/npm:/var/cache/outpost/npm',
        Runtime::PUBLISHED_IMAGE,
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'npm', 'ci', '--no-fund', '--no-audit',
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'npm', 'run', 'build',
    ]);
    Process::assertDidntRun(fn (PendingProcess $process) => in_array('key:generate', $process->command, true));
});

it('rebuilds a current legacy sqlite instance around its single managed database', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        database: 'sqlite',
        services: ['mysql', 'redis', 'mailpit'],
        image: Runtime::PUBLISHED_IMAGE,
        imageDigest: $this->currentDigest,
    ));

    File::put($this->worktree.'/.env', implode("\n", [
        'APP_KEY=base64:existing',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=/app/database/database.sqlite',
    ])."\n");

    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x'])
        ->assertSuccessful();

    $manifest = app(Outposts::class)->find('feature-x');
    $environment = File::get($this->worktree.'/.env');

    expect($manifest?->database)->toBe('mysql')
        ->and($manifest?->services)->toBe(['mysql', 'redis', 'mailpit'])
        ->and($environment)->toContain('DB_CONNECTION=mysql')
        ->and($environment)->toContain('DB_HOST=127.0.0.1')
        ->and($environment)->toContain('DB_DATABASE=outpost')
        ->and($environment)->not->toContain('/app/database/database.sqlite');
});

it('reruns repository-owned setup hooks after rebuilding a container', function () {
    config(['outpost.hooks.setup' => [
        'search' => ['@php', 'artisan', 'scout:sync-index-settings'],
    ]]);

    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x'])
        ->expectsOutputToContain('Running setup hook [search]')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'php8.4', 'artisan', 'scout:sync-index-settings',
    ]);
});

it('rejects malformed setup hooks before deleting an existing container', function () {
    config(['outpost.hooks.setup' => [
        'search' => 'php artisan scout:sync-index-settings',
    ]]);

    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x'])
        ->expectsOutputToContain('outpost.hooks.setup.search')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[1] ?? null, ['stop', 'delete', 'run'], true));
});

it('refuses a dirty worktree before deleting its container', function () {
    fakeUpgradeProcesses($this->root, $this->currentDigest, [
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(" M app/Invoice.php\n?? notes.txt\n"),
    ]);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('uncommitted changes')
        ->expectsOutputToContain('M app/Invoice.php')
        ->expectsOutputToContain('notes.txt')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[1] ?? null, ['stop', 'delete', 'run'], true));
});

it('reuses recorded path repository mounts during a forced rebuild without another flag', function () {
    $package = $this->root.'/package-source';
    $mount = "{$package}:{$package}:ro";

    File::ensureDirectoryExists($package);
    File::put($this->worktree.'/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => $package]],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        image: Runtime::PUBLISHED_IMAGE,
        imageDigest: $this->currentDigest,
        pathRepositoryMounts: [$mount],
    ));
    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('Reusing 1 previously approved path repository mount')
        ->assertSuccessful();

    expect(app(Outposts::class)->find('feature-x')?->pathRepositoryMounts)->toBe([$mount]);

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run'
        && in_array($mount, $process->command, true));
});

it('recovers approved mounts from host bridges for legacy manifests', function () {
    $package = $this->root.'/package-source';
    $mount = "{$package}:{$package}:ro";

    File::ensureDirectoryExists($package);
    File::put($this->worktree.'/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => $package]],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    app(PathRepositories::class)
        ->scan($this->worktree, $this->app->basePath())
        ->createHostBridges(dirname($this->worktree));
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        image: Runtime::PUBLISHED_IMAGE,
        imageDigest: $this->currentDigest,
    ));
    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x', '--force' => true])
        ->assertSuccessful();

    expect(app(Outposts::class)->find('feature-x')?->pathRepositoryMounts)->toBe([$mount]);
    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run'
        && in_array($mount, $process->command, true));
});

it('keeps recorded mounts while requiring approval only for newly discovered repositories', function () {
    $approvedPackage = $this->root.'/approved-package';
    $newPackage = $this->root.'/new-package';
    $approvedMount = "{$approvedPackage}:{$approvedPackage}:ro";
    $newMount = "{$newPackage}:{$newPackage}:ro";

    File::ensureDirectoryExists($approvedPackage);
    File::ensureDirectoryExists($newPackage);
    File::put($this->worktree.'/composer.json', json_encode([
        'repositories' => [
            ['type' => 'path', 'url' => $approvedPackage],
            ['type' => 'path', 'url' => $newPackage],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        image: Runtime::PUBLISHED_IMAGE,
        imageDigest: $this->currentDigest,
        pathRepositoryMounts: [$approvedMount],
    ));
    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x', '--force' => true])
        ->expectsConfirmation('Mount this newly discovered path repository read-only into the instance?', 'no')
        ->expectsOutputToContain('Keeping 1 previously approved mount')
        ->assertSuccessful();

    expect(app(Outposts::class)->find('feature-x')?->pathRepositoryMounts)->toBe([$approvedMount]);
    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run'
        && in_array($approvedMount, $process->command, true)
        && ! in_array($newMount, $process->command, true));
});

it('force rebuilds a current image from the latest Outpost configuration', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        image: Runtime::PUBLISHED_IMAGE,
        imageDigest: $this->currentDigest,
    ));

    File::put($this->worktree.'/.env', implode("\n", [
        'APP_KEY=base64:existing',
        'APP_URL=http://feature-x-app.outpost',
        'DB_CONNECTION=mysql',
        'DB_HOST=127.0.0.1',
        'REDIS_PASSWORD=password',
        'CUSTOM_VALUE=preserved',
    ])."\n");

    $tls = $this->root.'/tls';
    File::ensureDirectoryExists($tls);
    File::put($tls.'/domain', "outpost\n");
    File::put($tls.'/trusted', "mkcert\n");
    File::ensureDirectoryExists($this->runtime.'/tls');
    File::put($this->runtime.'/tls/certificate.pem', 'certificate');
    File::put($this->runtime.'/tls/key.pem', 'key');

    config([
        'database.default' => null,
        'outpost.https' => true,
        'outpost.tls.path' => $tls,
        'outpost.php' => ['8.4'],
        'outpost.frontend' => 'none',
        'outpost.services' => ['redis', 'mailpit'],
        'outpost.expose_services' => false,
        'outpost.processes' => [
            'queue' => ['@php', 'artisan', 'queue:work'],
        ],
        'outpost.resources.cpus' => 6,
        'outpost.resources.memory' => '3G',
    ]);

    fakeUpgradeProcesses($this->root, $this->currentDigest, [
        processPattern('mkcert', '-cert-file').' *' => Process::result('created'),
    ]);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('Rebuilt [feature-x] from the current Outpost configuration')
        ->expectsOutputToContain('worktree and branch [feature/billing] are preserved')
        ->assertSuccessful();

    $manifest = app(Outposts::class)->find('feature-x');
    $environment = File::get($this->worktree.'/.env');

    expect($manifest?->url)->toBe('https://feature-x-app.outpost')
        ->and($manifest?->services)->toBe(['redis', 'mailpit'])
        ->and($manifest?->exposeServices)->toBeFalse()
        ->and($manifest?->processes)->toBe(['queue'])
        ->and($manifest?->frontend)->toBe('none')
        ->and($manifest?->cpus)->toBe(6)
        ->and($manifest?->memory)->toBe('3G')
        ->and(File::get($this->runtime.'/nginx.conf'))->toContain('listen 443 ssl default_server;')
        ->and(File::get($this->runtime.'/supervisord.conf'))->toContain('[program:outpost-queue]')
        ->and(File::get($this->runtime.'/supervisord.conf'))->toContain('/usr/bin/redis-server')
        ->and($environment)->toContain('APP_URL=https://feature-x-app.outpost')
        ->and($environment)->toContain('CUSTOM_VALUE=preserved')
        ->and($environment)->not->toContain('DB_CONNECTION=')
        ->and($environment)->not->toContain('DB_HOST=')
        ->and($environment)->not->toContain('REDIS_PASSWORD=');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'mkcert',
        '-cert-file', $this->runtime.'/tls/certificate.pem',
        '-key-file', $this->runtime.'/tls/key.pem',
        'feature-x-app.outpost',
    ]);
    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run'
        && in_array('6', $process->command, true)
        && in_array('3G', $process->command, true));
    Process::assertDidntRun(fn (PendingProcess $process) => in_array('npm', $process->command, true));
});

it('adopts an explicitly configured database service during a forced rebuild', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        database: 'sqlite',
        services: [],
        image: Runtime::PUBLISHED_IMAGE,
        imageDigest: $this->currentDigest,
    ));

    File::put($this->worktree.'/.env', "APP_KEY=base64:existing\nDB_CONNECTION=sqlite\nDB_DATABASE=/app/database/database.sqlite\n");

    config([
        'database.default' => 'sqlite',
        'outpost.services' => ['mysql'],
    ]);

    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x', '--force' => true])
        ->assertSuccessful();

    $manifest = app(Outposts::class)->find('feature-x');
    $environment = File::get($this->worktree.'/.env');

    expect($manifest?->database)->toBe('mysql')
        ->and($manifest?->services)->toBe(['mysql'])
        ->and($environment)->toContain('DB_CONNECTION=mysql')
        ->and($environment)->toContain('DB_HOST=127.0.0.1')
        ->and($environment)->toContain('DB_DATABASE=outpost')
        ->and($environment)->not->toContain('/app/database/database.sqlite');
});

it('preflights trusted https before a forced rebuild deletes the container', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        image: Runtime::PUBLISHED_IMAGE,
        imageDigest: $this->currentDigest,
    ));

    config([
        'outpost.https' => true,
        'outpost.tls.path' => $this->root.'/missing-tls',
    ]);

    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('outpost:certify')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[1] ?? null, ['stop', 'delete', 'run'], true));
});

it('preflights every selected worktree before upgrading any of them', function () {
    $secondWorktree = $this->root.'/feature-y/app';
    $secondRuntime = $this->root.'/feature-y/runtime';

    File::ensureDirectoryExists($secondWorktree);
    File::ensureDirectoryExists($secondRuntime);
    File::put($secondWorktree.'/.env', "APP_KEY=base64:existing\n");
    File::put($secondWorktree.'/composer.json', '{}');
    File::put($secondRuntime.'/nginx.conf', 'nginx');
    File::put($secondRuntime.'/supervisord.conf', 'supervisor');

    app(Outposts::class)->save(fakeManifest(
        name: 'feature-y',
        image: 'ghcr.io/zacksmash/outpost:0.1.1',
        imageDigest: $this->oldDigest,
    ));

    fakeUpgradeProcesses($this->root, $this->currentDigest, [
        processPattern('git', '-C', $secondWorktree, 'status', '--short') => Process::result("?? rescue.patch\n"),
    ], [
        ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ['id' => 'feature-y-app', 'status' => ['state' => 'running']],
    ]);

    $this->artisan('outpost:upgrade', ['--all' => true])
        ->expectsOutputToContain('[feature-y] worktree has uncommitted changes')
        ->expectsOutputToContain('?? rescue.patch')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[1] ?? null, ['stop', 'delete', 'run'], true));
});

it('leaves a current running instance alone but rebuilds one whose container is missing', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'feature-x',
        image: Runtime::PUBLISHED_IMAGE,
        imageDigest: $this->currentDigest,
    ));

    fakeUpgradeProcesses($this->root, $this->currentDigest);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x'])
        ->expectsOutputToContain('already uses the configured image')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[1] ?? null, ['stop', 'delete', 'run'], true));

    fakeUpgradeProcesses($this->root, $this->currentDigest, containers: []);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x'])
        ->expectsOutputToContain('Recreated [feature-x]')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'delete');
});

it('pulls a missing configured image before touching the instance', function () {
    fakeUpgradeProcesses($this->root, $this->currentDigest, [
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::sequence()
            ->push(Process::result('', 'not found', 1))
            ->push(Process::result(fakeImageInspect($this->currentDigest))),
        processPattern('container', 'image', 'pull', Runtime::PUBLISHED_IMAGE) => Process::result('pulled'),
    ]);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x'])
        ->expectsOutputToContain('Pulling the missing')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'image', 'pull', Runtime::PUBLISHED_IMAGE,
    ]);
});

it('fails before deletion when the configured image contract is incompatible', function () {
    fakeUpgradeProcesses($this->root, $this->currentDigest, [
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect(
            $this->currentDigest,
            [Runtime::IMAGE_RUNTIME_PATH_LABEL => '/outpost'],
        )),
    ]);

    $exit = Artisan::call('outpost:upgrade', ['name' => 'feature-x']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('requires [/etc/outpost]')
        ->and($output)->toContain('outpost:build --force');

    Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[1] ?? null, ['stop', 'delete', 'run'], true));
});

it('requires either one name or the all option, but not both', function () {
    Process::fake();

    $this->artisan('outpost:upgrade', ['name' => 'feature-x', '--all' => true])
        ->expectsOutputToContain('Choose an instance name or --all, not both')
        ->assertFailed();

    Process::assertNothingRan();
});
