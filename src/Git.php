<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class Git
{
    /**
     * Cached remote names for this command invocation.
     *
     * @var list<string>|null
     */
    protected ?array $remotes = null;

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
     * Get every useful remote-tracking branch name.
     *
     * @return list<string>
     */
    public function remoteBranches(): array
    {
        $result = $this->runOrFail(
            ['git', 'for-each-ref', '--format=%(refname:short)', 'refs/remotes'],
            'Unable to list the remote branches',
        );

        $branches = [];

        foreach (explode("\n", trim($result->output())) as $branch) {
            $branch = trim($branch);

            if ($branch === ''
                || str_ends_with($branch, '/HEAD')
                || preg_match('#^[^/]+/pull/[0-9]+$#', $branch) === 1) {
                continue;
            }

            $branches[] = $branch;
        }

        return $branches;
    }

    /**
     * Get concise local and remote branch suggestions.
     *
     * Remote branches are omitted when their local counterpart already
     * exists, because selecting that local branch is non-destructive.
     *
     * @return list<string>
     */
    public function branchSuggestions(): array
    {
        $local = $this->branches();
        $suggestions = $local;

        foreach ($this->remoteBranches() as $remote) {
            $separator = strpos($remote, '/');
            $branch = $separator === false ? $remote : substr($remote, $separator + 1);

            if (! in_array($branch, $local, true)) {
                $suggestions[] = $remote;
            }
        }

        return array_values(array_unique($suggestions));
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
     * Resolve the local branch name represented by a local or remote reference.
     */
    public function localBranchName(string $reference): string
    {
        if ($this->branchExists($reference)) {
            return $reference;
        }

        $remote = $this->remoteReference($reference);

        return $remote['branch'] ?? $reference;
    }

    /**
     * Get every branch currently checked out in a worktree.
     *
     * @return list<string>
     */
    public function checkedOutBranches(): array
    {
        $result = $this->runOrFail(
            ['git', 'worktree', 'list', '--porcelain'],
            'Unable to list the existing worktrees',
        );

        $branches = [];

        foreach (explode("\n", $result->output()) as $line) {
            if (str_starts_with($line = trim($line), 'branch refs/heads/')) {
                $branches[] = substr($line, strlen('branch refs/heads/'));
            }
        }

        return $branches;
    }

    /**
     * Determine if the given branch is checked out in any worktree.
     */
    public function branchCheckedOut(string $branch): bool
    {
        return in_array($branch, $this->checkedOutBranches(), true);
    }

    /**
     * Add a worktree for a local, remote, or new branch reference.
     */
    public function addWorktree(string $path, string $reference): void
    {
        if ($this->branchExists($reference)) {
            $this->runOrFail(
                ['git', 'worktree', 'add', '--', $path, $reference],
                "Unable to create a worktree for the branch [{$reference}]",
            );

            return;
        }

        $remote = $this->remoteReference($reference);

        if ($remote !== null && ! $this->branchExists($remote['branch'])) {
            $this->fetchRemoteBranch($remote['remote'], $remote['branch']);

            $this->runOrFail(
                [
                    'git', 'worktree', 'add', '--track', '-b', $remote['branch'],
                    '--', $path, $reference,
                ],
                "Unable to create a worktree for the remote branch [{$reference}]",
            );

            return;
        }

        $local = $remote['branch'] ?? $reference;
        $command = $this->branchExists($local)
            ? ['git', 'worktree', 'add', '--', $path, $local]
            : ['git', 'worktree', 'add', '-b', $local, '--', $path];

        $this->runOrFail($command, "Unable to create a worktree for the branch [{$local}]");
    }

    /**
     * Fetch a GitHub pull request and add it as an editable local worktree.
     */
    public function addPullRequestWorktree(string $path, int $pullRequest, string $remote): void
    {
        $branch = "outpost/pr-{$pullRequest}";

        if ($this->branchExists($branch)) {
            $this->addWorktree($path, $branch);

            return;
        }

        if (! in_array($remote, $this->remoteNames(), true)) {
            throw new RuntimeException("The [{$remote}] git remote does not exist.");
        }

        $tracking = "{$remote}/pull/{$pullRequest}";

        $this->runOrFail(
            [
                'git', 'fetch', '--no-tags', $remote,
                "+refs/pull/{$pullRequest}/head:refs/remotes/{$tracking}",
            ],
            "Unable to fetch GitHub pull request #{$pullRequest} from [{$remote}]",
        );

        $this->runOrFail(
            ['git', 'worktree', 'add', '-b', $branch, '--', $path, $tracking],
            "Unable to create a worktree for GitHub pull request #{$pullRequest}",
        );
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
     * Prune worktree registrations whose directories no longer exist.
     */
    public function pruneWorktrees(): void
    {
        $this->runOrFail(
            ['git', 'worktree', 'prune'],
            'Unable to prune the stale worktrees',
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
     * Fetch a remote branch into its remote-tracking reference.
     */
    protected function fetchRemoteBranch(string $remote, string $branch): void
    {
        $this->runOrFail(
            [
                'git', 'fetch', '--no-tags', $remote,
                "+refs/heads/{$branch}:refs/remotes/{$remote}/{$branch}",
            ],
            "Unable to fetch the [{$remote}/{$branch}] remote branch",
        );
    }

    /**
     * Split a reference whose first component names a configured remote.
     *
     * @return array{remote: string, branch: string}|null
     */
    protected function remoteReference(string $reference): ?array
    {
        if (! str_contains($reference, '/')) {
            return null;
        }

        $remotes = $this->remoteNames();

        usort($remotes, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($remotes as $remote) {
            $prefix = $remote.'/';

            if (str_starts_with($reference, $prefix) && strlen($reference) > strlen($prefix)) {
                return [
                    'remote' => $remote,
                    'branch' => substr($reference, strlen($prefix)),
                ];
            }
        }

        return null;
    }

    /**
     * Get every configured git remote name.
     *
     * @return list<string>
     */
    protected function remoteNames(): array
    {
        if ($this->remotes !== null) {
            return $this->remotes;
        }

        $result = $this->runOrFail(['git', 'remote'], 'Unable to list the git remotes');
        $remotes = [];

        foreach (explode("\n", trim($result->output())) as $remote) {
            if (($remote = trim($remote)) !== '') {
                $remotes[] = $remote;
            }
        }

        return $this->remotes = $remotes;
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
