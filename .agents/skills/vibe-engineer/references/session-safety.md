# Vibe Engineering — Session Safety

Read this file before session setup, commits, undo, or finish. The purpose is to isolate the session without losing or committing unrelated work.

## Track four states

- **Original workspace:** repository root, original branch, intended base commit, and the complete initial output of `git status --porcelain=v1 --untracked-files=all`.
- **Session baseline:** the exact `HEAD` of the isolated branch or worktree before the first edit. Verify that it is the intended base commit. Use it to review the complete feature. A full-session discard requires separate confirmation and an exact recovery plan.
- **Batch baseline:** `HEAD` when the current uncommitted batch starts. Update it after every approved commit. Use it for `reject` or `undo all`.
- **Task record:** the paths, before/after diff, validation, and checkpoint limitations for one atomic task. Use it for task-level undo.

The **session file set** contains every path changed by the main agent, a formatter, generator, hook, or subagent. It excludes the developer's pre-existing or concurrent changes. Record both paths of a rename. Reconcile after every task with Git status and command output. `git diff` alone is not enough because it omits untracked files. Stop on a change whose ownership cannot be proved; do not silently adopt it into the session. Do not touch ignored files unless the task explicitly requires them and their exact before-state is recorded.

## Establish the workspace

Inspect state while still in Plan Mode, but do not mutate it. After the developer approves the plan:

1. Confirm that the directory is a Git repository with at least one commit. If it is not, explain that branch, commit, and baseline restore commands are unavailable. Ask the developer to create a baseline commit or explicitly accept a limited workflow before editing.
2. If the tree is clean, verify that the proposed branch name does not already exist, then create a short task branch with `git switch -c <branch>`. If the current branch is already task-specific, ask before reusing it.
3. If the tree is dirty, prefer a dedicated worktree through `EnterWorktree`. This leaves the developer's current files untouched. By default, Claude Code may base a new worktree on the remote default branch rather than the current local `HEAD`, and may briefly fetch that ref. If remote access is not intended, offer an exact local-base worktree: after validating a new path and branch name, run `git worktree add <path> -b <branch> <full-intended-base-sha>`, then call `EnterWorktree` with that path. Immediately verify the returned branch and full `HEAD` against the intended base commit before editing. If either differs, make no edits, call `ExitWorktree`, preserve the unexpected worktree and branch, and report expected versus actual state. Ask before removing or recreating anything; never continue on the wrong base. `worktree.baseRef: head` is an alternative only after local `HEAD` is verified as the intended base and the developer approves that configuration.
4. A new worktree omits ordinary uncommitted files. Gitignored files are copied only when `.worktreeinclude` selects them. Inspect the new worktree's initial status, record any copied files as pre-existing and off-limits, and ask before transferring anything else. If the task depends on original uncommitted changes, stop and ask how the developer wants to preserve or transfer them.
5. If a worktree is unavailable, offer a stash only with explicit approval. Use `git stash push --include-untracked -m "vibe pre-session: <slug>"`, then record the exact stash commit ID from `refs/stash`, verify the tree is clean, and create the branch. A stash does not include ignored files, so never claim it protects them.
6. If the developer declines both isolation options, keep every initially dirty path off-limits. Modify one only after explicit per-file consent and an exact before-state record. State clearly that this is not full isolation.

Never restore or drop a pre-session stash automatically. At finish, report its exact commit ID and original branch. The safe recovery flow is to switch back, run `git stash apply --index <exact-stash-commit>`, verify both content and staged state, and drop the stash only after the developer explicitly asks. If `--index` conflicts, preserve the stash and stop for manual recovery.

## Commit one approved batch

Build an exact path list from the verified session file set. Then run:

```bash
git --literal-pathspecs add -A -- <session-files>
git --literal-pathspecs diff --cached --name-status -- <session-files>
git diff --cached --name-only
git --literal-pathspecs commit --only -m "vibe: <summary>" -- <session-files>
```

The full staged-path check exposes unrelated work that was staged before the session. Mention it, but do not unstage or commit it. `git commit --only ... -- <session-files>` keeps those unrelated staged paths out of the commit. Do not edit between preview and commit. Afterward, inspect the commit's path list and the complete status; hooks can mutate or stage files. If an unexpected path entered the commit, stop and report it rather than rewriting history automatically. After a verified commit, set the batch baseline to the new `HEAD`.

## Reject the current batch

Confirm the batch baseline and exact session paths first.

For session files that existed at the batch baseline:

```bash
git --literal-pathspecs restore --source=<batch-baseline> --staged --worktree -- <tracked-session-files>
```

For paths created in the current batch, first verify each path is still session-owned and is not a directory. If any is staged, unstage only those exact paths first:

```bash
git --literal-pathspecs restore --staged -- <staged-created-files>
```

Then delete only the exact created files, using a direct file-editing tool or `rm -- <exact-created-files>`. Never use recursive deletion, a glob, `git clean`, or a tree-wide restore. For a rename, restore the old path and unstage/remove the exact new path. Do not touch any path that was dirty at session start. Re-run `git status --short` and verify that no rejected path remains in either index or worktree.

## Undo one task

Reverse the selected task's recorded patch, not the whole file. Before applying it, check whether later tasks changed any of the same hunks or depend on a file it created. If they did, show the overlap and ask the developer how to proceed.

Claude Code checkpoints can help with the most recent task only when all relevant edits came from direct file-editing tools. Checkpoints do not track Bash changes and usually do not restore subagent, other-session, manual, symlinked, or hard-linked changes. When those sources are involved, reconstruct and review an exact reverse patch from the task's recorded before-state instead. If the task boundary cannot be isolated, do not guess.

## Finish safely

Before pushing, confirm the current branch is the session branch, the working-tree state, completion checks, destination remote, upstream, and exact outgoing commits. Inspect the outgoing diff for secrets, sensitive configuration, and unrelated paths. Keep unrelated staged or untracked work out of the session summary. Leave a dedicated worktree or recorded stash in place unless the developer explicitly asks to clean it up or restore it.
