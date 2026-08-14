<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Zacksmash\Outpost\Git;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->git = new Git('/projects/app');
});

it('runs every command inside the repository', function () {
    Process::fake();

    $this->git->hasCommits();

    Process::assertRan(fn (PendingProcess $process) => $process->path === '/projects/app'
        && $process->command === ['git', 'rev-parse', 'HEAD']);
});

it('knows whether the repository has commits', function () {
    Process::fake([
        processPattern('git', 'rev-parse', 'HEAD') => Process::result('', 'fatal: unknown revision', 128),
    ]);

    expect($this->git->hasCommits())->toBeFalse();
});

it('lists the local branches', function () {
    Process::fake([
        processPattern('git', 'branch', '--format=%(refname:short)') => Process::result("main\nfeature/billing\n\n"),
    ]);

    expect($this->git->branches())->toBe(['main', 'feature/billing']);
});

it('knows whether a branch exists', function () {
    Process::fake([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/main') => Process::result('abc123'),
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/missing') => Process::result('', '', 1),
    ]);

    expect($this->git->branchExists('main'))->toBeTrue()
        ->and($this->git->branchExists('missing'))->toBeFalse();
});

it('lists every branch checked out in a worktree', function () {
    Process::fake([
        processPattern('git', 'worktree', 'list', '--porcelain') => Process::result(implode("\n", [
            'worktree /projects/app',
            'HEAD abc123',
            'branch refs/heads/main',
            '',
            'worktree /projects/app/.outpost/feature-x/app',
            'HEAD def456',
            'branch refs/heads/feature-x',
            '',
            'worktree /projects/detached',
            'HEAD 789abc',
            'detached',
        ])),
    ]);

    expect($this->git->checkedOutBranches())->toBe(['main', 'feature-x']);
});

it('detects a branch checked out in another worktree', function () {
    Process::fake([
        processPattern('git', 'worktree', 'list', '--porcelain') => Process::result(implode("\n", [
            'worktree /projects/app',
            'HEAD abc123',
            'branch refs/heads/main',
            '',
            'worktree /projects/app/.outpost/feature-x/app',
            'HEAD def456',
            'branch refs/heads/feature-x',
            '',
            'worktree /projects/detached',
            'HEAD 789abc',
            'detached',
        ])),
    ]);

    expect($this->git->branchCheckedOut('main'))->toBeTrue()
        ->and($this->git->branchCheckedOut('feature-x'))->toBeTrue()
        ->and($this->git->branchCheckedOut('feature'))->toBeFalse()
        ->and($this->git->branchCheckedOut('feature-x-2'))->toBeFalse();
});

it('adds a worktree for an existing branch', function () {
    Process::fake([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature-x') => Process::result('abc123'),
        processPattern('git', 'worktree', 'add', '--', '/projects/app/.outpost/feature-x/app', 'feature-x') => Process::result(''),
    ]);

    $this->git->addWorktree('/projects/app/.outpost/feature-x/app', 'feature-x');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'worktree', 'add', '--', '/projects/app/.outpost/feature-x/app', 'feature-x',
    ]);
});

it('creates the branch when adding a worktree for a new branch', function () {
    Process::fake([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature-new') => Process::result('', '', 1),
        processPattern('git', 'worktree', 'add', '-b', 'feature-new', '--', '/tmp/wt') => Process::result(''),
    ]);

    $this->git->addWorktree('/tmp/wt', 'feature-new');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'worktree', 'add', '-b', 'feature-new', '--', '/tmp/wt',
    ]);
});

it('surfaces the real error when a worktree cannot be created', function () {
    Process::fake([
        processPattern('git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature-x') => Process::result('abc123'),
        processPattern('git', 'worktree', 'add').' *' => Process::result('', "fatal: 'feature-x' is already used by worktree", 128),
    ]);

    $this->git->addWorktree('/tmp/wt', 'feature-x');
})->throws(RuntimeException::class, "Unable to create a worktree for the branch [feature-x]: fatal: 'feature-x' is already used by worktree");

it('removes a worktree by force', function () {
    Process::fake();

    $this->git->removeWorktree('/tmp/wt');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'worktree', 'remove', '--force', '--', '/tmp/wt',
    ]);
});

it('deletes a branch', function () {
    Process::fake();

    $this->git->deleteBranch('feature-x');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'branch', '-D', '--', 'feature-x',
    ]);
});
