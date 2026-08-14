<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
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

it('skips every confirmation when forced', function () {
    fakeRemoval();

    $this->artisan('outpost:remove', ['name' => 'feature-x', '--force' => true])
        ->assertSuccessful();

    expect(File::isDirectory($this->root.'/feature-x'))->toBeFalse();

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('-D', $process->command, true));
});

it('can forget local instance state without contacting a stuck runtime', function () {
    File::ensureDirectoryExists($this->root.'/feature-x/app');

    fakeRemoval();

    $this->artisan('outpost:remove', [
        'name' => 'feature-x',
        '--force' => true,
        '--forget' => true,
    ])
        ->expectsOutputToContain('Skipped container [feature-x-app]')
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
