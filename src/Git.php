<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class Git
{
    /**
     * Create a new git manager for the given repository.
     */
    public function __construct(protected readonly string $basePath) {}

    /**
     * Determine if the application is a git repository with at least one commit.
     */
    public function hasCommits(): bool
    {
        return $this->run(['git', 'rev-parse', 'HEAD'])->successful();
    }

    /**
     * Get the currently checked out branch, or null when detached.
     */
    public function currentBranch(): ?string
    {
        $branch = trim($this->runOrFail(
            ['git', 'branch', '--show-current'],
            'Unable to determine the current branch',
        )->output());

        return $branch === '' ? null : $branch;
    }

    /**
     * Get every local branch name.
     *
     * @return list<string>
     */
    public function branches(): array
    {
        $result = $this->runOrFail(
            ['git', 'branch', '--format=%(refname:short)'],
            'Unable to list the local branches',
        );

        $branches = [];

        foreach (explode("\n", trim($result->output())) as $branch) {
            if (($branch = trim($branch)) !== '') {
                $branches[] = $branch;
            }
        }

        return $branches;
    }

    /**
     * Determine if the given local branch exists.
     */
    public function branchExists(string $branch): bool
    {
        return $this->run([
            'git', 'rev-parse', '--verify', '--quiet', "refs/heads/{$branch}",
        ])->successful();
    }

    /**
     * Determine if the given branch is checked out in any worktree.
     */
    public function branchCheckedOut(string $branch): bool
    {
        $result = $this->runOrFail(
            ['git', 'worktree', 'list', '--porcelain'],
            'Unable to list the existing worktrees',
        );

        foreach (explode("\n", $result->output()) as $line) {
            if (trim($line) === "branch refs/heads/{$branch}") {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a worktree for the given branch, creating the branch if needed.
     */
    public function addWorktree(string $path, string $branch): void
    {
        $command = $this->branchExists($branch)
            ? ['git', 'worktree', 'add', '--', $path, $branch]
            : ['git', 'worktree', 'add', '-b', $branch, '--', $path];

        $this->runOrFail($command, "Unable to create a worktree for the branch [{$branch}]");
    }

    /**
     * Remove the worktree at the given path.
     */
    public function removeWorktree(string $path): void
    {
        $this->runOrFail(
            ['git', 'worktree', 'remove', '--force', '--', $path],
            "Unable to remove the worktree at [{$path}]",
        );
    }

    /**
     * Delete the given local branch.
     */
    public function deleteBranch(string $branch): void
    {
        $this->runOrFail(
            ['git', 'branch', '-D', '--', $branch],
            "Unable to delete the branch [{$branch}]",
        );
    }

    /**
     * Run the given command inside the repository.
     *
     * @param  list<string>  $command
     */
    protected function run(array $command): ProcessResult
    {
        return Process::path($this->basePath)->run($command);
    }

    /**
     * Run the given command or throw with its real error output.
     *
     * @param  list<string>  $command
     */
    protected function runOrFail(array $command, string $message): ProcessResult
    {
        $result = $this->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                "{$message}: ".trim($result->errorOutput() ?: $result->output()),
            );
        }

        return $result;
    }
}
