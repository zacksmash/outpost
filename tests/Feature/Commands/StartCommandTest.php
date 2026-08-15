<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Runtime;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-start-'.Str::random(10);

    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest('feature-x'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('starts a stopped instance and flushes the dns cache', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'start', 'feature-x-app') => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'curl').' *' => Process::result(''),
        processPattern('dscacheutil', '-flushcache') => Process::result(''),
    ]);

    $this->artisan('outpost:start', ['name' => 'feature-x'])
        ->expectsOutputToContain('Started: http://feature-x-app.outpost')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'start', 'feature-x-app',
    ]);
});

it('leaves an already running instance alone', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->artisan('outpost:start', ['name' => 'feature-x'])
        ->expectsOutputToContain('already running')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'start');
});

it('points at the logs when the instance starts but never answers', function () {
    Sleep::fake();

    config(['outpost.timeout' => 2]);

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'start', 'feature-x-app') => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'curl').' *' => Process::result('', 'refused', 7),
    ]);

    $this->artisan('outpost:start', ['name' => 'feature-x'])
        ->expectsOutputToContain('did not answer within 2 seconds')
        ->expectsOutputToContain('php artisan outpost:logs feature-x')
        ->assertFailed();
});

it('points a missing container at the explicit recreation path', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $this->artisan('outpost:start', ['name' => 'feature-x'])
        ->expectsOutputToContain('container is missing')
        ->expectsOutputToContain('php artisan outpost:start feature-x --recreate')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'start');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'run');
});

it('recreates a missing container without replacing the surviving worktree', function () {
    $worktree = $this->root.'/feature-x/app';
    $runtime = $this->root.'/feature-x/runtime';
    $git = $this->root.'/git';

    File::ensureDirectoryExists($worktree);
    File::ensureDirectoryExists($runtime);
    File::ensureDirectoryExists($git);
    File::put($worktree.'/.env', "APP_KEY=base64:existing\nAPP_URL=http://localhost\n");
    File::put($worktree.'/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => sys_get_temp_dir()]],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    File::put($runtime.'/nginx.conf', 'nginx');
    File::put($runtime.'/supervisord.conf', 'supervisor');

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::sequence()
            ->push(Process::result('', 'not found', 1))
            ->push(Process::result(json_encode([
                ['variants' => [['config' => ['config' => ['Labels' => [
                    Runtime::IMAGE_RUNTIME_PATH_LABEL => Runtime::IMAGE_RUNTIME_PATH,
                ]]]]]],
            ], JSON_THROW_ON_ERROR))),
        processPattern('container', 'image', 'pull', Runtime::PUBLISHED_IMAGE) => Process::result('pulled'),
        processPattern('git', 'rev-parse', '--path-format=absolute', '--git-common-dir') => Process::result($git."\n"),
        processPattern('id', '-u') => Process::result("501\n"),
        processPattern('id', '-g') => Process::result("20\n"),
        processPattern('container', 'run').' *' => Process::result(''),
        processPattern('container', 'exec').' *' => Process::result(''),
        processPattern('dscacheutil', '-flushcache') => Process::result(''),
    ]);

    $this->artisan('outpost:start', [
        'name' => 'feature-x',
        '--recreate' => true,
        '--mount-path-repos' => true,
    ])
        ->expectsOutputToContain('Recreated: http://feature-x-app.outpost')
        ->assertSuccessful();

    expect(File::get($worktree.'/.env'))->toContain('APP_KEY=base64:existing')
        ->and(app(Outposts::class)->find('feature-x')?->status)->toBe('ready')
        ->and(is_link($this->root.'/feature-x'.sys_get_temp_dir()))->toBeTrue();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'run', '--detach', '--name', 'feature-x-app', '--dns', '1.1.1.1',
        '--cpus', '4', '--memory', '2G', '--env', 'OUTPOST_UID=501', '--env', 'OUTPOST_GID=20',
        '--volume', $worktree.':/app',
        '--volume', $runtime.':/etc/outpost:ro',
        '--volume', "{$git}:{$git}:ro",
        '--volume', sys_get_temp_dir().':'.sys_get_temp_dir().':ro',
        Runtime::PUBLISHED_IMAGE,
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'image', 'pull', Runtime::PUBLISHED_IMAGE,
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'php8.4', 'artisan', 'migrate', '--force',
    ]);
    Process::assertDidntRun(fn (PendingProcess $process) => in_array('key:generate', $process->command, true));
});

it('refuses an unknown instance', function () {
    Process::fake();

    $this->artisan('outpost:start', ['name' => 'missing'])
        ->expectsOutputToContain('The [missing] instance does not exist.')
        ->assertFailed();
});
