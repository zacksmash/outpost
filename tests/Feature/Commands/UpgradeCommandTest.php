<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;
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
        ->expectsOutputToContain('Kept: source worktree and branch [feature/billing]')
        ->expectsOutputToContain('Reset: container-local database and service data')
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
        '--volume', $this->worktree.':/app',
        '--volume', $this->runtime.':/etc/outpost:ro',
        '--volume', $this->git.':'.$this->git.':ro',
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

it('refuses a dirty worktree before deleting its container', function () {
    fakeUpgradeProcesses($this->root, $this->currentDigest, [
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(" M app/Invoice.php\n?? notes.txt\n"),
    ]);

    $this->artisan('outpost:upgrade', ['name' => 'feature-x'])
        ->expectsOutputToContain('uncommitted changes')
        ->expectsOutputToContain('M app/Invoice.php')
        ->expectsOutputToContain('notes.txt')
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
