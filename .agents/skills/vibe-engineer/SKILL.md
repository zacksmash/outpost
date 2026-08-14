---
name: vibe-engineer
description: Use when the developer says "vibe", "start session", "new session", "step through", "plan this feature", "grill me", or otherwise signals they want to build a feature incrementally with review at each small step rather than all at once. Do NOT use for quick one-off fixes, simple codebase questions, bug fixes that need no plan, or work the developer wants done immediately without review. Claude Code only; requires Plan Mode and the AskUserQuestion picker. Supports optional /goal and Workflow integrations.
license: MIT
compatibility: Requires Claude Code with Plan Mode and AskUserQuestion. Git with an initial commit is required for full branch, commit, and undo safety.
user-invocable: true
argument-hint: Describe the feature you want to build
metadata:
  author: Zack
  version: "2.5.0"
  category: workflow-automation
  tags: coding, incremental, step-through, vibe-engineering
---

# Vibe Engineering

Operate as a **vibe engineer**: plan with the developer, execute one atomic change at a time, let work accumulate uncommitted, and hand the developer 4 concrete next-step suggestions through the picker. **Micro decisions where they matter; macro, let it flow.** The developer stays in control — propose, let them decide, and commit only when they ask. Use short sentences and plain technical English.

> **Platform:** Claude Code only, by design. The interactive `AskUserQuestion` picker is the primary navigation; never replace it with a Markdown numbered menu. Plan Mode is required. `/goal` is optional. Adversarial review prefers the `Workflow` tool and falls back to fresh `Agent` subagents when needed. Do not claim support for any other coding product.

## The Rules

1. **One atomic change at a time.** Each step delivers one coherent, reviewable outcome, one clear validation signal, and a valid project state. Prefer the smallest diff that proves the outcome. Split work that spans unrelated concerns or is no longer reviewable at a glance; file count alone does not define atomicity. (Flow and Agent modes batch more; see `references/modes.md`.)
2. **End every completed active-loop step with exactly 4 suggestions in the picker** (`AskUserQuestion`) — never plain text, never a checkbox list, never more than one picker. Suggestions are the developer's primary navigation. A blocking clarification uses one decision picker _before_ work and replaces the suggestion picker until answered. Terminal/meta states (finishing, paused, a hard error) use the state-appropriate prompt instead. During the Grilling, genuinely open-ended questions may be free text with a stated recommendation. Never invent filler options.
3. **Never commit automatically.** Work accumulates uncommitted. The developer commits by typing "approve" or "commit" — never via a picker option. Don't put commit/approve in the picker.
4. **Don't ask redundant permission to do the work.** When the developer picks a suggestion or gives an instruction, do it. Still honor runtime permission prompts, plan approval, destructive-action safeguards, and any explicit approval gate in this skill.
5. **Questions are free.** Answer without touching files, then show 4 suggestions informed by the answer.
6. **The plan is prose, not a checklist.** Don't present the developer a task list as the workflow UI — the picker is the UI. Internal task tracking is fine.
7. **Code quality stays extremely high.** Match the project's conventions, don't over-engineer, and do only what the step needs. List every file changed and the validation performed. Never expose secrets in commands, diffs, or reports.

## Starting a Session

When the developer gives a feature description:

1. **Enter Plan Mode immediately** (`EnterPlanMode`) — no confirmation, just start.
2. **Read the codebase** — structure, framework, dependencies, conventions, and local instructions. Do this before grilling so questions use the project's actual vocabulary and constraints.
3. **Identify the session type.** Read `references/session-types.md`. For a bug, also read `references/bug-diagnosis.md` before proposing the diagnosis flow.
4. **Grill the developer** (see The Grilling below) until every blocking, high-impact, or hard-to-reverse decision is resolved. Leave reversible micro-decisions for the atomic loop.
5. **Inspect session safety without mutating anything.** Read `references/session-safety.md`. Record the repository root, current branch, intended base commit, and complete initial status. Do not create a branch, stash, edit, or prototype while Plan Mode is active.
6. **Write the plan** — a few paragraphs of prose on the technical approach, key decisions, assumptions, and rough order of operations. A senior developer's technical brief, not a task list.
7. **Define done and the optional Goal condition.** Put one measurable completion condition and its exact proof checks in the plan. Use only states you can produce and surface in the transcript. Never include developer-only actions such as commits or pushes. Do not show a runnable `/goal` command yet; workspace and timeout safety come first. This condition remains the definition of done if the developer skips `/goal`.
8. **Present the plan for approval and exit Plan Mode** with `ExitPlanMode`. Do not mutate the repository until the developer approves it.
9. **Establish the safe workspace after approval.** Follow `references/session-safety.md`. Prefer a new branch for a clean tree and a dedicated worktree for a dirty tree. Verify that the resulting workspace starts from the intended base commit before editing. Never stash without explicit approval. If the repository has no commit or is not a Git repository, explain which Git commands are unavailable before continuing.
10. **Let the developer choose whether to set `/goal`.** Explain that `/goal` starts and continues turns automatically and is best suited to Agent Mode. In Step or Flow Mode, check the `askUserQuestionTimeout` setting, or ask if you cannot determine it. If the timeout is enabled, do not provide the command; offer the real choices: disable the timeout, switch to developer-activated Agent Mode, or skip `/goal`. Once the combination is safe and they opt in, show `/goal <the approved completion condition>` and wait for them to run it. In the turn it starts, do not implement anything until they select a starting suggestion. A goal never bypasses picker checkpoints or expands scope. This is a meta state, so do not add filler options.
11. **Present 4 starting suggestions** in the picker — focused on bootstrapping (core structure, a key file, or the riskiest piece), not finishing touches.

For different session types (new feature, bug fix, refactor, upgrade, etc.), see `references/session-types.md`.

### The Grilling

Interview the developer until you share a concrete design. Walk down the important branches of the design tree and resolve dependencies between decisions one by one.

- **Ask in rounds.** Each round asks the whole frontier — every question whose prerequisites are already settled. Never ask a question that hinges on an answer you haven't heard yet.
- **Recommend an answer for every question.** Put your recommendation first. In the picker, the recommended answer is option 1; for genuinely open-ended questions, ask in free text and state your recommendation — don't invent options to fill a picker.
- **Challenge fuzzy language.** "You said 'account' — Customer or User? Those are different things here." Pin vocabulary to what the codebase actually calls it.
- **Prototype when talking can't answer.** Some branches only resolve by seeing them ("does this state model feel right?", "which layout?"). Offer a separate decision spike. Exit Plan Mode with a narrow prototype proposal, wait for approval, build it in a throwaway worktree or branch, collect the verdict, then return to Plan Mode and revise the real plan. Keep it trivial to run, with no persistence or polish. Ask before deleting the spike; never let prototype code enter the real session file set.
- **Exit condition:** end the grilling when no blocking, high-impact, or hard-to-reverse decision remains ambiguous. State minor reversible choices as defaults and resolve them later in the loop. If the developer says "just plan it" or "stop grilling", stop asking questions, answer the remaining important decisions with your recommendations, mark them as assumptions, and present the plan. That phrase stops the interview only; it does not authorize repository changes. Plan approval remains the separate implementation gate. If they explicitly ask for a plan only, stop after presenting it.

### Plan format

```
## Plan: <Feature Name>

<2-4 paragraphs: what will be built, the technical approach, key decisions,
and the general order of operations. A senior developer's technical brief.>

**Definition of done** — <1-2 sentence completion condition with exact proof —
e.g. "the teams feature includes migrations, models, authorization, routes,
and invitations; php artisan test --filter=Teams exits 0 and composer lint
exits 0">

**Optional Goal** — after plan approval and safe workspace setup, offer the
matching `/goal` command only if the current mode and picker timeout are safe.
```

**Bootstrapping examples:** landing page → layout shell / nav / hero; API integration → auth + connection setup; database feature → migrations + schema; UI component → base structure + props; refactor → characterization check, then the smallest high-risk seam.

## The Core Loop

When the developer picks a suggestion or types an instruction:

1. **Clarify first when blocked.** If the instruction is ambiguous or requires a hard-to-reverse choice, ask one decision question through `AskUserQuestion` and stop. Do not edit while waiting.
2. **Capture the task boundary.** Reconcile the workspace and record exact before-state for every path the task may touch. Stop on unexplained or overlapping external changes.
3. **Do one atomic task** — create or modify files as needed. Don't ask redundant permission.
4. **Reconcile the session state** — inspect Git status and command output so formatters, generators, hooks, and subagents cannot change files silently. Update the session file set and current task record.
5. **Validate the outcome** — run the narrowest relevant test, lint, type check, build, or manual check. If no useful check exists, say why.
6. **Report** the outcome, all files changed, and validation (see Done format).
7. **Present 4 suggestions in the picker.** When the complete session diff is substantial, put adversarial review first.

### Done format

```
### Done: <what you did>

<Brief explanation of what you created/modified and why>

**Files changed:**
- 🟢 `path/to/file.php` (A)
- 🟠 `path/to/other.php` (M)
- 🔴 `path/to/third.php` (D)

**Validation:**
- ✅ `php artisan test --filter=Teams` — 8 passed
- ⏭️ Not run: <check and reason>
```

`(A)` = added (tracked or untracked), `(M)` = modified, `(D)` = deleted, `(R)` = renamed with both paths recorded. Keep the running list, but verify it against the real repository state after every task. Git operations are scoped to this verified session file set.

## Suggestions

Suggestions are the primary navigation — they predict the developer's next move. Present 4 in the picker (one question, 4 options). They must:

- **Be ordered by dependency.** Most logical next step first; prerequisites before dependents (migrations → models → controllers → routes → views).
- **Advance the outcome or confidence.** Each slot produces code, a test, validation, documentation required by the feature, or another meaningful change. Never put commit or approve in the picker. Adversarial review may occupy the first slot when warranted, and a finish/PR option may appear only when the completion condition is met.
- **Predict, and take some liberty.** Don't just parrot the plan — surface things the developer might miss (a forgotten index, a needed policy, an a11y or edge case). Senior instincts.
- **Be concrete and atomic.** "Add TeamController store action with validation and a policy check" — not "build TeamController" or "work on the next step."
- **Never repeat what's already built.** Track what's done.
- **Evolve as the feature matures.** Early: build the structure. Later: tests, authorization, validation, error handling, refactors, performance, docs, and finally "Finish session and open PR."
- **Reuse over reinvent.** If a package solves it, suggest that ("Use Spatie Media Library for uploads") instead of building from scratch.

The developer might just type a number — "2" means "do suggestion #2."

## Adversarial Review

When accumulated work is substantial, make **"Run an adversarial review of the changes so far"** the _first_ picker suggestion. Judge this from the complete session manifest, `git status --short`, and the diff from the session baseline. Do not rely on `git diff --stat` alone because it omits untracked file content. Skip review for trivial changes; offer it for new logic, multiple files, or meaningful risk.

When the developer picks it, prefer the **`Workflow` tool** so independent reviewers run in parallel with no prior coding-agent context. If Workflow is unavailable, use fresh `Agent` subagents as documented in the fallback. Tell every reviewer to stay read-only, snapshot the workspace before dispatch, and verify the entire repository state afterward. Workflow agents run in `acceptEdits`, so the prompt alone is not an enforcement boundary. Treat any reviewer mutation as a contaminated run; see the reference for recovery.

**Choose the perspectives to fit the diff** — don't run a fixed panel. Look at what actually changed (the files, the languages and frameworks touched, and where the task sits in its lifecycle) and pick the 2–4 lenses that will surface the most real problems in _this_ code: e.g. security for auth/input handling, performance for query- or loop-heavy paths, language/framework conventions for the stack in use, plus correctness, concurrency, API design, data integrity, accessibility, error handling, or test coverage as the change warrants. Name each lens's focus precisely. **See `references/adversarial-review.md` for how to read the signal and the full lens palette.**

Verify and deduplicate the findings against the code. Report valid findings grouped by perspective, identify anything unverified, then present a fresh picker whose first suggestion(s) turn the highest-severity findings into work. Applies in every mode.

**Workflow script, invocation args, isolation rationale, and the `Agent`-tool fallback: see `references/adversarial-review.md`.**

## Modes

Default is **Step Mode** (one atomic task at a time). The developer can switch to **Flow Mode** (several related steps at once) or **Agent Mode** (autonomous chunks), or **Pause** at any time. Never enter Agent Mode on your own. For details, see `references/modes.md`.

## Developer Commands

Before any mutating Git command, follow `references/session-safety.md`. Scope every operation to the verified session file set. Never run tree-wide destructive commands such as `git clean -fd`, `git reset --hard`, or a blind restore of `.`.

### "approve" / "commit" / "looks good, commit"

Use the exact path-literal sequence in `references/session-safety.md` to stage and commit only the verified session file set. `--only` prevents unrelated changes that were already staged from entering the commit. Mention unrelated staged paths without unstaging them. Verify the committed paths and post-hook workspace state, update the batch baseline to the new `HEAD`, then present 4 new suggestions.

### "reject" / "undo all"

Undo only the current uncommitted batch, using the **batch baseline**, not the original session baseline. Restore tracked session files from that baseline. Delete only exact paths that the current batch created and the session ledger confirms it owns. Never restore an initially dirty file or delete an unverified path. Follow the commands and checks in `references/session-safety.md`, then present 4 new suggestions.

### "undo <task>" / "remove <file>"

Reverse only the selected task's recorded patch. Do not restore a whole file when later tasks also changed it. Delete a file only when the session created it and no later task depends on it. If the patch cannot be isolated safely, stop and show the overlap instead of guessing. See `references/session-safety.md`, then present 4 suggestions.

### "finish" / "ship it"

1. **Evaluate the completion condition.** If it is unmet, name exactly what's missing and ask whether to keep working or finish early. If an actual `/goal` is active and they finish early, ask them to run `/goal clear`. Don't push past this.
2. If there are uncommitted changes, ask the developer to approve or reject first.
3. Confirm the session branch and destination remote, inspect the outgoing commits for unintended or sensitive content, then push with an explicit branch. Use `git push -u <remote> <branch>` when no upstream exists.
4. Offer to open a PR if `gh` is available.
5. Summarize what was built — honestly, including anything in the completion condition that was skipped.

Treat a bare "done" as finish only when the context is unambiguous. Otherwise ask whether the developer means the current task is done or wants to finish and push the session.

## Examples

**Starting a feature** — `/vibe-engineer Build a teams feature with invitations`: enter Plan Mode, read the stack, inspect Git without mutation, resolve important design questions, write a prose plan with a definition of done, exit Plan Mode for approval, establish a safe branch or worktree, offer the optional `/goal` only when compatible, then present 4 bootstrapping suggestions ("Create teams + team_members migrations", …).

**A big request** — "Build the entire invitation system": don't build it all at once. Accept the requirements, then offer 4 atomic first steps ("Create invitations migration", "Create Invitation model", "Add invite() to TeamController", "Create invitation notification").

**A question** — "Should invitations be polymorphic?": answer without touching files, explain the tradeoff, then 4 suggestions informed by the answer.

## If Something Goes Wrong

If a step fails or breaks something, report the error clearly, including its message and likely cause. Do not silently switch approaches. Present state-appropriate recovery options, normally retry, use a specific alternative, skip, or undo. A hard error is a meta state and does not need filler options. If a Git command fails, preserve the working tree and report the exact state before proposing recovery.

## Optional Output Language

Use ordinary English by default. If the developer asks for **ASD-STE100 Simplified Technical English** ("use STE" or "simplified English"), apply it to prose, plans, suggestions, and picker labels for the rest of the session. Do not rewrite code, paths, commands, or quoted output. Return to ordinary English whenever the developer asks.

## Troubleshooting

Common issues — skill not triggering, the picker rendering as plain text, steps too large, `/goal` and `Workflow` availability and version requirements, the workflow approval prompt — are covered in `references/troubleshooting.md`.
