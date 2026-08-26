<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-remove-'.Str::random(10);

    config(['outpost.path' => $this->root]);

    app(Outposts::class)->save(fakeManifest('feature-x'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function fakeRemoval(array $overrides = []): void
{
    Process::fake($overrides + [
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'stop', 'feature-x-app') => Process::result(''),
        processPattern('container', 'delete', 'feature-x-app') => Process::result(''),
        processPattern('git', '-C').' *'.processPattern('status', '--short') => Process::result(''),
        processPattern('git', 'worktree', 'remove', '--force').' *' => Process::result(''),
        processPattern('git', 'worktree', 'prune') => Process::result(''),
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature/billing') => Process::result('', '', 1),
        processPattern('dscacheutil', '-flushcache') => Process::result(''),
    ]);
}

it('removes nothing when declined', function () {
    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x'])
        ->expectsConfirmation('Remove the [feature-x] instance? Its container, worktree, and data will be destroyed.', 'no')
        ->expectsOutputToContain('Nothing removed.')
        ->assertSuccessful();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeTrue();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'delete');
});

it('removes the container, worktree, and instance directory when confirmed', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');

    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x'])
        ->expectsConfirmation('Remove the [feature-x] instance? Its container, worktree, and data will be destroyed.', 'yes')
        ->expectsOutputToContain('Removed [feature-x].')
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['container', 'delete', 'feature-x-app']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'worktree', 'remove', '--force', '--', $this->root.'/feature-x/app',
    ]);
});

it('runs repository-owned teardown hooks before destroying the instance', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');
    config(['outpost.hooks.teardown' => [
        'cleanup' => ['@php', 'artisan', 'outpost:cleanup'],
    ]]);
    $hookRan = false;

    fakeRemoval([
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'php8.4', 'artisan', 'outpost:cleanup') => function () use (&$hookRan) {
            $hookRan = true;

            return Process::result('clean');
        },
        processPattern('container', 'stop', 'feature-x-app') => function () use (&$hookRan) {
            expect($hookRan)->toBeTrue();

            return Process::result('');
        },
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('Running teardown hook [cleanup]')
        ->assertSuccessful();
});

it('keeps the complete instance when a teardown hook fails', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');
    config(['outpost.hooks.teardown' => [
        'cleanup' => ['@php', 'artisan', 'outpost:cleanup'],
    ]]);

    fakeRemoval([
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'php8.4', 'artisan', 'outpost:cleanup') => Process::result('', 'cleanup failed', 1),
    ]);

    $exit = Artisan::call('outpost:remove', ['name' => 'feature-x', '--force' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Running teardown hook [cleanup] failed')
        ->and($output)->toContain('cleanup failed');

    expect(File::isDirectory($this->root.'/feature-x'))->toBeTrue();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'stop');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'delete');
    Process::assertDidntRun(fn (PendingProcess $process) => array_slice($process->command, 1, 2) === ['worktree', 'remove']);
});

it('does not treat files written by a successful teardown hook as new user work', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');
    config(['outpost.hooks.teardown' => [
        'cleanup' => ['@php', 'artisan', 'outpost:cleanup'],
    ]]);

    fakeRemoval([
        processPattern('git', '-C', $this->root.'/feature-x/app', 'status', '--short') => Process::sequence()
            ->push(Process::result(''))
            ->push(Process::result("?? cleanup.log\n")),
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'php8.4', 'artisan', 'outpost:cleanup') => Process::result('clean'),
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'stop');
});

it('skips teardown hooks and directly deletes a stopped container', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');
    config(['outpost.hooks.teardown' => [
        'cleanup' => ['@php', 'artisan', 'outpost:cleanup'],
    ]]);

    fakeRemoval([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('Skipped configured teardown hooks because container state is [stopped]')
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'stop');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'delete', 'feature-x-app',
    ]);
});

it('skips teardown hooks while provisioning is incomplete', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');
    app(Outposts::class)->save(fakeManifest('feature-x', status: 'failed'));
    config(['outpost.hooks.teardown' => [
        'cleanup' => ['@php', 'artisan', 'outpost:cleanup'],
    ]]);

    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('provisioning status is [failed]')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('explicitly skips teardown hooks when forgetting local state', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');
    config(['outpost.hooks.teardown' => [
        'cleanup' => ['@php', 'artisan', 'outpost:cleanup'],
    ]]);

    fakeRemoval();

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--forget' => true,
    ])
        ->expectsOutputToContain('Teardown hooks will not run')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'container');
});

it('does not parse malformed hooks when forgetting local state', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');
    config(['outpost.hooks' => [
        'teardwon' => ['cleanup' => 'php artisan cleanup'],
    ]]);

    fakeRemoval();

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--forget' => true,
    ])
        ->expectsOutputToContain('Removed [feature-x]')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'container');
});

it('skips every confirmation when forced', function () {
    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('-D', $process->command, true));
    Process::assertDidntRun(fn (PendingProcess $process) => in_array('--verify', $process->command, true));
});

it('refuses to remove a dirty worktree even when forced', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');

    fakeRemoval([
        processPattern('git', '-C', $this->root.'/feature-x/app', 'status', '--short') => Process::result(" M app/Test.php\n"),
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('uncommitted changes')
        ->expectsOutputToContain('--discard-changes')
        ->assertFailed();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeTrue();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'delete');
});

it('removes a proven legacy Redis dump instead of treating it as user work', function () {
    $worktree = $this->root.'/feature-x/app';
    $runtime = $this->root.'/feature-x/runtime';

    File::ensureDirectoryExists($worktree);
    File::ensureDirectoryExists($runtime);
    File::put($runtime.'/supervisord.conf', "[program:redis]\ncommand=/usr/bin/redis-server --bind 127.0.0.1\n");
    File::put($worktree.'/dump.rdb', 'REDIS0010legacy');

    fakeRemoval([
        processPattern('git', '-C', $worktree, 'status', '--short') => Process::sequence()
            ->push(Process::result("?? dump.rdb\n"))
            ->push(Process::result('')),
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('Removed legacy Redis data file [dump.rdb] from [feature-x]')
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();
});

it('removes a dirty worktree only with the explicit discard option', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');

    fakeRemoval([
        processPattern('git', '-C', $this->root.'/feature-x/app', 'status', '--short') => Process::result(" M app/Test.php\n"),
    ]);

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--discard-changes' => true,
    ])->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();
});

it('can forget local instance state without contacting a stuck runtime', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');

    fakeRemoval();

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--forget' => true,
    ])
        ->expectsOutputToContain('Container [feature-x-app] may remain')
        ->expectsOutputToContain('Remove it later')
        ->expectsOutputToContain('Removed [feature-x].')
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'container');
});

it('cleans up a half-created instance gracefully', function () {
    fakeRemoval([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'delete');
    Process::assertDidntRun(fn (PendingProcess $process) => array_slice($process->command, 1, 2) === ['worktree', 'remove']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'worktree', 'prune']);
});

it('tolerates a failed stop and deletes the container by force', function () {
    fakeRemoval([
        processPattern('container', 'stop', 'feature-x-app') => Process::result('', 'timed out', 1),
        processPattern('container', 'delete', '--force', 'feature-x-app') => Process::result(''),
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'delete', '--force', 'feature-x-app',
    ]);
});

it('offers to remove an instance whose manifest is unreadable', function () {
    File::put($this->root.'/feature-x/outpost.json', '{broken');

    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x'])
        ->expectsConfirmation('The [feature-x] manifest is unreadable, so its container cannot be determined. Remove the instance directory anyway?', 'yes')
        ->expectsOutputToContain('Removed [feature-x].')
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();
});

it('never acts on a manifest naming an unexpected container', function () {
    File::put($this->root.'/feature-x/outpost.json', json_encode([
        ...fakeManifest('feature-x')->toArray(),
        'container' => 'someone-elses-box',
    ], JSON_THROW_ON_ERROR));

    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x'])
        ->expectsConfirmation('The [feature-x] manifest is unreadable, so its container cannot be determined. Remove the instance directory anyway?', 'no')
        ->expectsOutputToContain('Nothing removed.')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('someone-elses-box', $process->command, true));
});

it('rejects a name that is not a slug with a clean error', function () {
    Process::fake();

    $this->artisan('outpost:remove', ['name' => 'My Instance', '--force' => true])
        ->expectsOutputToContain('must be a URL-friendly slug')
        ->assertFailed();

    Process::assertNothingRan();
});

it('refuses removal with a remedy when the worktree cannot be inspected', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');

    fakeRemoval([
        processPattern('git', '-C').' *'.processPattern('status', '--short') => Process::result('', 'fatal: not a git repository', 128),
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->expectsOutputToContain('--discard-changes')
        ->assertFailed();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeTrue();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'delete');
});

it('refuses non-interactive removal without the explicit force option', function () {
    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--no-interaction' => true])
        ->expectsOutputToContain('Refusing to remove non-interactively without --force.')
        ->assertFailed();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeTrue();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'delete');
});

it('preserves the caller mode flags in the non-interactive remedy', function () {
    fakeRemoval();

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--forget' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('php artisan outpost:remove feature-x --force --forget')
        ->assertFailed();
});

it('warns when the branch cannot be determined from an unreadable manifest', function () {
    File::put($this->root.'/feature-x/outpost.json', '{broken');

    fakeRemoval();

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--delete-branch' => true,
    ])
        ->expectsOutputToContain('no branch was deleted')
        ->expectsOutputToContain('Removed [feature-x].')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('-D', $process->command, true));
});

it('removes non-interactively with the explicit force option', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');

    fakeRemoval();

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Removed [feature-x].')
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();
});

it('refuses non-interactive removal of a broken instance without the explicit force option', function () {
    File::put($this->root.'/feature-x/outpost.json', '{broken');

    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--no-interaction' => true])
        ->expectsOutputToContain('Refusing to remove non-interactively without --force.')
        ->assertFailed();

    expect(File::exists($this->root.'/feature-x/outpost.json'))->toBeTrue();
});

it('deletes the branch without prompting when explicitly requested', function () {
    fakeRemoval([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature/billing') => Process::result('abc123'),
        processPattern('git', 'worktree', 'list', '--porcelain') => Process::result("worktree /projects/app\nbranch refs/heads/main\n"),
        processPattern('git', 'branch', '-D', '--', 'feature/billing') => Process::result(''),
    ]);

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--delete-branch' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Deleted the [feature/billing] branch.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'branch', '-D', '--', 'feature/billing',
    ]);
});

it('warns instead of failing when the requested branch is checked out elsewhere', function () {
    fakeRemoval([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature/billing') => Process::result('abc123'),
        processPattern('git', 'worktree', 'list', '--porcelain') => Process::result("worktree /projects/app\nbranch refs/heads/feature/billing\n"),
    ]);

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--delete-branch' => true,
    ])
        ->expectsOutputToContain('was not deleted because it is checked out')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('-D', $process->command, true));
});

it('offers to delete the branch when it is safe', function () {
    fakeRemoval([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature/billing') => Process::result('abc123'),
        processPattern('git', 'worktree', 'list', '--porcelain') => Process::result("worktree /projects/app\nbranch refs/heads/main\n"),
        processPattern('git', 'branch', '-D', '--', 'feature/billing') => Process::result(''),
    ]);

    $this->artisan('outpost:remove', ['name' => 'feature-x'])
        ->expectsConfirmation('Remove the [feature-x] instance? Its container, worktree, and data will be destroyed.', 'yes')
        ->expectsConfirmation('Delete the [feature/billing] branch too?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'branch', '-D', '--', 'feature/billing',
    ]);
});
