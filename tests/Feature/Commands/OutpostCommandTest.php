<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Zacksmash\Outpost\ApplicationHttps;
use Zacksmash\Outpost\Detection;
use Zacksmash\Outpost\Detector;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-create-'.Str::random(10);

    config([
        'outpost.path' => $this->root,
        'outpost.https' => false,
    ]);

    $this->doctor = Mockery::mock(Doctor::class);
    $this->doctor->shouldReceive('inspect')->byDefault()->andReturn([
        DoctorCheck::pass(Doctor::RUNTIME_CHECK, 'The Apple container system is running.'),
        DoctorCheck::pass(Doctor::BASE_IMAGE_CHECK, 'The base image is available.'),
    ]);
    $this->doctor->shouldReceive('requiresSetup')->byDefault()->andReturnFalse();
    app()->instance(Doctor::class, $this->doctor);

    File::ensureDirectoryExists($this->root.'/feature-x/app');
    File::put($this->root.'/feature-x/app/.env.example', "APP_NAME=Example\nDB_CONNECTION=sqlite\n");
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function fakeCreation(array $overrides = []): void
{
    Process::fake($overrides + [
        processPattern('git', 'rev-parse', 'HEAD') => Process::result('abc123'),
        processPattern('container', 'system', 'dns', 'list') => Process::result("DOMAIN\noutpost\n"),
        processPattern('container', 'system', 'property', 'list', '--format', 'json') => Process::result('{"dns":{"domain":"outpost"}}'),
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.0') => Process::result('[]'),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
        processPattern('git', 'branch', '--show-current') => Process::result("main\n"),
        processPattern('git', 'branch', '--format=%(refname:short)') => Process::result("main\nfeature-x\n"),
        processPattern('git', 'for-each-ref', '--format=%(refname:short)', 'refs/remotes') => Process::result(''),
        processPattern('git', 'worktree', 'list', '--porcelain') => Process::result("worktree /projects/app\nbranch refs/heads/main\n"),
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature-x') => Process::result('abc123'),
        processPattern('git', 'worktree', 'add').' *' => Process::result(''),
        processPattern('git', 'rev-parse', '--path-format=absolute', '--git-common-dir') => Process::result("/projects/app/.git\n"),
        processPattern('id', '-u') => Process::result("501\n"),
        processPattern('id', '-g') => Process::result("20\n"),
        processPattern('container', 'run').' *' => Process::result(''),
        processPattern('container', 'exec').' *' => Process::result(''),
        processPattern('dscacheutil', '-flushcache') => Process::result(''),
    ]);
}

it('prepares missing prerequisites and continues creating the instance', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->times(3)->andReturn(
        [DoctorCheck::failure(Doctor::BASE_IMAGE_CHECK, 'The base image is missing.', 'Pull it.')],
        [DoctorCheck::failure(Doctor::BASE_IMAGE_CHECK, 'The base image is missing.', 'Pull it.')],
        [DoctorCheck::pass(Doctor::BASE_IMAGE_CHECK, 'The base image is available.')],
    );
    $doctor->shouldReceive('requiresSetup')->once()->andReturnTrue();
    app()->instance(Doctor::class, $doctor);

    fakeCreation([
        processPattern('container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.1.0') => Process::result('pulled'),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
        ->expectsOutputToContain('The instance is ready')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.1.0',
    ]);
});

it('does not begin setup or instance creation when preparation is declined', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn([
        DoctorCheck::failure(Doctor::BASE_IMAGE_CHECK, 'The base image is missing.', 'Pull it.'),
    ]);
    $doctor->shouldReceive('requiresSetup')->once()->andReturnTrue();
    app()->instance(Doctor::class, $doctor);

    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsConfirmation('Prepare Outpost now?', 'no')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'pull');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'worktree');
});

it('explains explicit setup in non-interactive runs instead of prompting', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure(Doctor::BASE_IMAGE_CHECK, 'The base image is missing.', 'Pull it.'),
    ]);
    $doctor->shouldReceive('requiresSetup')->once()->andReturnTrue();
    app()->instance(Doctor::class, $doctor);

    fakeCreation();

    $this->artisan('outpost', [
        'branch' => 'feature-x',
        '--name' => 'feature-x',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('php artisan outpost:install --force')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'worktree');
});

it('creates a fully provisioned instance', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('http://feature-x-laravel.outpost')
        ->assertSuccessful();

    $manifest = json_decode(File::get($this->root.'/feature-x/outpost.json'), true);

    expect($manifest['name'])->toBe('feature-x')
        ->and($manifest['container'])->toBe('feature-x-laravel')
        ->and($manifest['branch'])->toBe('feature-x')
        ->and($manifest['cpus'])->toBe(4)
        ->and($manifest['memory'])->toBe('2G')
        ->and($manifest['expose_services'])->toBeTrue()
        ->and($manifest['database'])->toBe('sqlite')
        ->and($manifest['status'])->toBe('ready');

    expect(File::exists($this->root.'/feature-x/runtime/nginx.conf'))->toBeTrue()
        ->and(File::exists($this->root.'/feature-x/runtime/supervisord.conf'))->toBeTrue()
        ->and(File::get($this->root.'/feature-x/app/.env'))->toContain('APP_URL=http://feature-x-laravel.outpost');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'run', '--detach', '--name', 'feature-x-laravel', '--dns', '1.1.1.1',
        '--cpus', '4', '--memory', '2G',
        '--env', 'OUTPOST_UID=501', '--env', 'OUTPOST_GID=20',
        '--volume', $this->root.'/feature-x/app:/app',
        '--volume', $this->root.'/feature-x/runtime:/etc/outpost:ro',
        '--volume', '/projects/app/.git:/projects/app/.git:ro',
        'ghcr.io/zacksmash/outpost:0.1.0',
    ]);

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['dscacheutil', '-flushcache']);
});

it('rejects invalid resource configuration before creating instance state', function (string $key, mixed $value) {
    config(["outpost.resources.{$key}" => $value]);

    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain("outpost.resources.{$key}")
        ->assertFailed();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeFalse();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'worktree');
})->with([
    'cpus' => ['cpus', 0],
    'memory' => ['memory', '1.5G'],
]);

it('configures and releases application processes after provisioning', function () {
    config(['outpost.processes' => [
        'queue' => ['@php', 'artisan', 'queue:work', '--sleep=1'],
    ]]);

    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->assertSuccessful();

    $manifest = json_decode(File::get($this->root.'/feature-x/outpost.json'), true);
    $supervisor = File::get($this->root.'/feature-x/runtime/supervisord.conf');

    expect($manifest['processes'])->toBe(['queue'])
        ->and($supervisor)->toContain('[program:outpost-queue]')
        ->and($supervisor)->toContain('"php8.5" "artisan" "queue:work" "--sleep=1"');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/root', '--user', 'root', '--workdir', '/app',
        'feature-x-laravel', 'touch', '/var/lib/outpost/ready',
    ]);
});

it('runs detected octane and vite development servers', function () {
    config([
        'octane' => ['server' => 'swoole'],
        'outpost.frontend' => 'vite',
    ]);

    File::put($this->root.'/feature-x/app/package.json', json_encode([
        'scripts' => ['dev' => 'vite', 'build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));

    $detector = Mockery::mock(Detector::class);
    $detector->shouldReceive('detect')->once()->andReturn(new Detection(
        services: [],
        deferred: [],
        database: 'sqlite',
        php: '8.5',
        server: 'octane',
        octaneServer: 'frankenphp',
        frontend: 'vite',
    ));
    app()->instance(Detector::class, $detector);

    fakeCreation();

    $exit = Artisan::call('outpost', ['branch' => 'feature-x', '--name' => 'feature-x']);

    expect($exit)->toBe(0);

    $manifest = json_decode(File::get($this->root.'/feature-x/outpost.json'), true);
    $nginx = File::get($this->root.'/feature-x/runtime/nginx.conf');
    $supervisor = File::get($this->root.'/feature-x/runtime/supervisord.conf');

    expect($manifest['server'])->toBe('octane')
        ->and($manifest['octane_server'])->toBe('frankenphp')
        ->and($manifest['frontend'])->toBe('vite')
        ->and($manifest['processes'])->toBe(['octane', 'vite'])
        ->and($nginx)->toContain('location @octane')
        ->and($supervisor)->toContain('[program:outpost-octane]')
        ->and($supervisor)->toContain('--server=frankenphp')
        ->and($supervisor)->toContain('[program:outpost-vite]')
        ->and($supervisor)->not->toContain('[program:php-fpm]');
});

it('boots a trusted https instance with its certificate mounted read only', function () {
    $tls = $this->root.'/tls';
    File::ensureDirectoryExists($tls);
    File::put($tls.'/domain', "outpost\n");
    File::put($tls.'/trusted', "mkcert\n");
    config([
        'outpost.https' => true,
        'outpost.tls.path' => $tls,
    ]);

    fakeCreation([
        processPattern('mkcert', '-cert-file').' *' => Process::result('created'),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('https://feature-x-laravel.outpost')
        ->assertSuccessful();

    $manifest = json_decode(File::get($this->root.'/feature-x/outpost.json'), true);
    $nginx = File::get($this->root.'/feature-x/runtime/nginx.conf');

    expect($manifest['url'])->toBe('https://feature-x-laravel.outpost')
        ->and($nginx)->toContain('listen 443 ssl default_server;')
        ->and(File::isDirectory($this->root.'/feature-x/runtime/tls'))->toBeTrue();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'mkcert',
        '-cert-file', $this->root.'/feature-x/runtime/tls/certificate.pem',
        '-key-file', $this->root.'/feature-x/runtime/tls/key.pem',
        'feature-x-laravel.outpost',
    ]);

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('*.outpost', $process->command, true));

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'run', '--detach', '--name', 'feature-x-laravel', '--dns', '1.1.1.1',
        '--cpus', '4', '--memory', '2G',
        '--env', 'OUTPOST_UID=501', '--env', 'OUTPOST_GID=20',
        '--volume', $this->root.'/feature-x/app:/app',
        '--volume', $this->root.'/feature-x/runtime:/etc/outpost:ro',
        '--volume', '/projects/app/.git:/projects/app/.git:ro',
        'ghcr.io/zacksmash/outpost:0.1.0',
    ]);
});

it('automatically boots an https instance when the primary application uses https', function () {
    $tls = $this->root.'/tls';
    File::ensureDirectoryExists($tls);
    File::put($tls.'/domain', "outpost\n");
    File::put($tls.'/trusted', "mkcert\n");
    config([
        'outpost.https' => 'auto',
        'outpost.tls.path' => $tls,
    ]);

    $applicationHttps = Mockery::mock(ApplicationHttps::class);
    $applicationHttps->shouldReceive('detected')->once()->andReturnTrue();
    app()->instance(ApplicationHttps::class, $applicationHttps);

    fakeCreation([
        processPattern('mkcert', '-cert-file').' *' => Process::result('created'),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('https://feature-x-laravel.outpost')
        ->assertSuccessful();

    $manifest = json_decode(File::get($this->root.'/feature-x/outpost.json'), true);

    expect($manifest['url'])->toBe('https://feature-x-laravel.outpost');
});

it('rejects malformed application process configuration before creating state', function () {
    config(['outpost.processes' => [
        'queue' => 'php artisan queue:work',
    ]]);

    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('outpost.processes.queue')
        ->assertFailed();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeFalse();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run');
});

it('creates an instance from a remote branch', function () {
    File::ensureDirectoryExists($this->root.'/review-invoices/app');
    File::put($this->root.'/review-invoices/app/.env.example', "APP_NAME=Example\nDB_CONNECTION=sqlite\n");

    fakeCreation([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/origin/review/invoices') => Process::result('', '', 1),
        processPattern('git', 'remote') => Process::result("origin\n"),
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/review/invoices') => Process::result('', '', 1),
        processPattern('git', 'fetch', '--no-tags', 'origin', '+refs/heads/review/invoices:refs/remotes/origin/review/invoices') => Process::result(''),
        processPattern('git', 'worktree', 'add', '--track', '-b', 'review/invoices', '--', $this->root.'/review-invoices/app', 'origin/review/invoices') => Process::result(''),
    ]);

    $this->artisan('outpost', [
        'branch' => 'origin/review/invoices',
        '--name' => 'review-invoices',
    ])->assertSuccessful();

    $manifest = json_decode(File::get($this->root.'/review-invoices/outpost.json'), true);

    expect($manifest['branch'])->toBe('review/invoices');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'fetch', '--no-tags', 'origin', '+refs/heads/review/invoices:refs/remotes/origin/review/invoices',
    ]);
});

it('creates an instance from a github pull request', function () {
    File::ensureDirectoryExists($this->root.'/pr-42/app');
    File::put($this->root.'/pr-42/app/.env.example', "APP_NAME=Example\nDB_CONNECTION=sqlite\n");

    fakeCreation([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/outpost/pr-42') => Process::result('', '', 1),
        processPattern('git', 'remote') => Process::result("origin\nupstream\n"),
        processPattern('git', 'fetch', '--no-tags', 'upstream', '+refs/pull/42/head:refs/remotes/upstream/pull/42') => Process::result(''),
        processPattern('git', 'worktree', 'add', '-b', 'outpost/pr-42', '--', $this->root.'/pr-42/app', 'upstream/pull/42') => Process::result(''),
    ]);

    $this->artisan('outpost', [
        '--pr' => '42',
        '--remote' => 'upstream',
    ])
        ->expectsQuestion('What should the instance be named?', 'pr-42')
        ->assertSuccessful();

    $manifest = json_decode(File::get($this->root.'/pr-42/outpost.json'), true);

    expect($manifest['name'])->toBe('pr-42')
        ->and($manifest['branch'])->toBe('outpost/pr-42');
});

it('rejects a branch argument combined with a pull request', function () {
    fakeCreation();

    $this->artisan('outpost', [
        'branch' => 'feature-x',
        '--pr' => '42',
    ])
        ->expectsOutputToContain('Choose either a branch or --pr')
        ->assertFailed();
});

it('rejects an invalid pull request number', function () {
    fakeCreation();

    $this->artisan('outpost', ['--pr' => 'nope'])
        ->expectsOutputToContain('positive whole number')
        ->assertFailed();
});

it('rejects an empty pull request remote', function () {
    fakeCreation();

    $this->artisan('outpost', [
        '--pr' => '42',
        '--remote' => '',
    ])
        ->expectsOutputToContain('must name a configured git remote')
        ->assertFailed();
});

it('opens a newly created instance when requested', function () {
    fakeCreation([
        processPattern('open', 'http://feature-x-laravel.outpost') => Process::result(''),
    ]);

    $this->artisan('outpost', [
        'branch' => 'feature-x',
        '--name' => 'feature-x',
        '--open' => true,
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'open', 'http://feature-x-laravel.outpost',
    ]);
});

it('keeps a ready instance when its browser cannot be opened', function () {
    fakeCreation([
        processPattern('open', 'http://feature-x-laravel.outpost') => Process::result('', 'no browser handler', 1),
    ]);

    $this->artisan('outpost', [
        'branch' => 'feature-x',
        '--name' => 'feature-x',
        '--open' => true,
    ])
        ->expectsOutputToContain('no browser handler')
        ->expectsOutputToContain('Open it manually: http://feature-x-laravel.outpost')
        ->assertSuccessful();
});

it('refuses to run outside a git repository with commits', function () {
    fakeCreation([
        processPattern('git', 'rev-parse', 'HEAD') => Process::result('', 'fatal: not a git repository', 128),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('git repository with at least one commit')
        ->assertFailed();
});

it('prints the dns registration command instead of running it', function () {
    fakeCreation([
        processPattern('container', 'system', 'dns', 'list') => Process::result("DOMAIN\nbox\n"),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('sudo container system dns create outpost')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'sudo');
});

it('refuses a domain the machine will never publish', function () {
    fakeCreation([
        processPattern('container', 'system', 'dns', 'list') => Process::result("DOMAIN\nbox\n"),
        processPattern('container', 'system', 'property', 'list', '--format', 'json') => Process::result('{"dns":{"domain":"box"}}'),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('publishes container hostnames under [box]')
        ->expectsOutputToContain('OUTPOST_DOMAIN=box')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run');
});

it('provisions the application before checking its final HTTP response', function () {
    $composerInstalled = false;

    fakeCreation([
        processPattern('container', 'exec').' *'.processPattern('feature-x-laravel', 'php8.5', '/usr/local/bin/composer').' *' => function () use (&$composerInstalled) {
            $composerInstalled = true;

            return Process::result();
        },
        processPattern('container', 'exec').' *'.processPattern('feature-x-laravel', 'curl').' *' => function () use (&$composerInstalled) {
            expect($composerInstalled)->toBeTrue();

            return Process::result();
        },
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->assertSuccessful();
});

it('requires the base image to be built first', function () {
    fakeCreation([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.0') => Process::result('', 'not found', 1),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('php artisan outpost:build')
        ->assertFailed();
});

it('refuses a branch that is already checked out elsewhere', function () {
    fakeCreation([
        processPattern('git', 'worktree', 'list', '--porcelain') => Process::result("worktree /projects/app\nbranch refs/heads/feature-x\n"),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('already checked out')
        ->assertFailed();
});

it('refuses an invalid instance name', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'Not A Slug'])
        ->expectsOutputToContain('URL-friendly slug')
        ->assertFailed();
});

it('refuses a name that is already an instance', function () {
    fakeCreation();

    File::put($this->root.'/feature-x/outpost.json', json_encode(fakeManifest('feature-x')->toArray()));

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();
});

it('prompts for the branch and name when not provided', function () {
    fakeCreation();

    $this->artisan('outpost')
        ->expectsQuestion('Which branch should the instance run?', 'feature-x')
        ->expectsQuestion('What should the instance be named?', 'feature-x')
        ->assertSuccessful();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeTrue();
});

it('mounts nothing when path repository mounting is declined', function () {
    fakeCreation();

    File::put($this->root.'/feature-x/app/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => sys_get_temp_dir()]],
    ], JSON_UNESCAPED_SLASHES));

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsConfirmation('Mount these path repositories read-only into the instance?', 'no')
        ->expectsOutputToContain('Mounting nothing')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array(sys_get_temp_dir().':'.sys_get_temp_dir().':ro', $process->command, true));
});

it('mounts path repositories read-only when confirmed', function () {
    fakeCreation();

    File::put($this->root.'/feature-x/app/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => sys_get_temp_dir()]],
    ], JSON_UNESCAPED_SLASHES));

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsConfirmation('Mount these path repositories read-only into the instance?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => in_array(sys_get_temp_dir().':'.sys_get_temp_dir().':ro', $process->command, true));
});

it('fails closed and mounts nothing when run non-interactively', function () {
    fakeCreation();

    File::put($this->root.'/feature-x/app/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => sys_get_temp_dir()]],
    ], JSON_UNESCAPED_SLASHES));

    $exit = Artisan::call('outpost', [
        'branch' => 'feature-x',
        '--name' => 'feature-x',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Mounting nothing');

    Process::assertDidntRun(fn (PendingProcess $process) => in_array(sys_get_temp_dir().':'.sys_get_temp_dir().':ro', $process->command, true));
});

it('mounts path repositories read-only with the explicit flag', function () {
    fakeCreation();

    File::put($this->root.'/feature-x/app/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => sys_get_temp_dir()]],
    ], JSON_UNESCAPED_SLASHES));

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x', '--mount-path-repos' => true])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => in_array(sys_get_temp_dir().':'.sys_get_temp_dir().':ro', $process->command, true));

    $bridge = $this->root.'/feature-x'.sys_get_temp_dir();

    expect(is_link($bridge))->toBeTrue()
        ->and(readlink($bridge))->toBe(sys_get_temp_dir());
});

it('seeds the database only when asked', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x', '--seed' => true])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => in_array('db:seed', $process->command, true));
});

it('leaves everything in place when the instance never answers', function () {
    Sleep::fake();

    fakeCreation([
        processPattern('container', 'exec').' *'.processPattern('feature-x-laravel', 'curl').' *' => Process::result('', 'refused', 7),
    ]);

    config(['outpost.timeout' => 3]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('did not answer HTTP within 3 seconds')
        ->expectsOutputToContain('php artisan outpost:remove feature-x')
        ->assertFailed();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeTrue();

    expect(json_decode(File::get($this->root.'/feature-x/outpost.json'), true)['status'])
        ->toBe('failed');
});

it('reports the failing provisioning step and keeps the container', function () {
    fakeCreation([
        processPattern('container', 'exec').' *'.processPattern('feature-x-laravel', 'curl').' *' => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('feature-x-laravel', 'php8.5', '/usr/local/bin/composer').' *' => Process::result('', 'could not resolve host', 1),
    ]);

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('Installing composer dependencies failed.')
        ->expectsOutputToContain('php artisan outpost:remove feature-x')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('migrate', $process->command, true));

    expect(json_decode(File::get($this->root.'/feature-x/outpost.json'), true)['status'])
        ->toBe('failed');
});
