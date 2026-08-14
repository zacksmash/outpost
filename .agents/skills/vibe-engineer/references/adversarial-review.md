# Vibe Engineering — Adversarial Review

Offer this from the main loop when the accumulated diff is substantial (see SKILL.md → Adversarial Review for *when*). Prefer the `Workflow` tool so reviewers run in parallel, context-isolated from the coding conversation; use the fallback when Workflow is unavailable.

## Why isolation matters

Each reviewer subagent is spawned fresh by the workflow runtime. It does **not** receive your reasoning, justifications, or session history. It receives only the review prompt, then grounds itself in the session diff, the full content of untracked session files, project instructions (`CLAUDE.md`/`AGENTS.md`), relevant domain or architecture docs, and the surrounding code one dependency hop out. Project context: yes. Coding-agent reasoning: never. A reviewer cannot rationalize a decision it never saw justified.

Each reviewer is told to find what's *wrong* in its lens — no praise, no summaries — and returns concise findings (severity, `file:line`, one sentence each).

**Read-only is an audited invariant, not a permission guarantee.** Workflow subagents always run in `acceptEdits`, so record the complete repository status and an exact recoverable pre-review state before dispatch. Reconcile the entire repository after the run, including paths outside the session file set. If any reviewer changed a file, stop: parallel results may describe different states. Isolate and reverse only the reviewer-owned patch when that is provably safe, re-run the affected validation, discard every finding from the contaminated run, and rerun the review. If the patch overlaps legitimate or concurrent work, do not restore a whole file; show the overlap and ask the developer.

## Choosing the perspectives

Don't run a fixed panel. Before dispatching, read what actually changed and pick the lenses that will surface the most real problems in *this* code. Workflow reviews can use meaningful tokens, so spend them where the risk is, not on a checklist.

Read the signal:

- **What changed** — auth, input handling, or anything touching untrusted data pulls in **security**; query-, loop-, or render-heavy code pulls in **performance**; a public API, shared module, or schema change pulls in **interface / compatibility / data integrity**.
- **Language & stack** — match the lens to the idioms in use: memory safety and lifetimes (Rust/C/C++), concurrency and data races (Go, threaded code), type safety and null handling (TypeScript), framework conventions (Eloquent relationships, form requests, policies for Laravel; hooks/composition for React).
- **Task stage** — early scaffolding favors architecture and conventions over polish; a maturing feature favors correctness, edge cases, and error handling; pre-ship favors security, performance, tests, and accessibility.

A palette to draw from (pick what fits, invent others the diff suggests): security, performance, language/framework conventions, correctness & logic, code quality & edge cases, concurrency, API / interface design, data integrity, accessibility, error handling, test coverage.

Pick 2 for a narrow but substantial diff and 3–4 when it spans concerns. State each lens's focus precisely so the reviewer hunts the specific failure modes in this diff instead of reciting generic advice.

## Workflow script

Adapt the prompt details; keep the shape.

```js
export const meta = {
  name: 'vibe-adversarial-review',
  description: 'Adversarial review of vibe session changes',
  phases: [{ title: 'Review' }],
}
const FINDINGS = {
  type: 'object', required: ['findings'],
  additionalProperties: false,
  properties: { findings: { type: 'array', items: {
    type: 'object', required: ['severity', 'location', 'issue'],
    additionalProperties: false,
    properties: { severity: { type: 'string', enum: ['critical', 'major', 'minor'] },
      location: { type: 'string' }, issue: { type: 'string' } } } } },
}
phase('Review')
const results = await parallel(args.perspectives.map(p => () =>
  agent(`Adversarial ${p.name} review of the session changes in ${args.cwd}.
This is a read-only review. Do not edit files or run state-changing commands.
The session baseline is ${args.baseline}. The complete session file list is:
${JSON.stringify(args.sessionFiles)}
Run "git status --short". Compare tracked session files with the baseline and
read the full content of every untracked session file; git diff omits them.
Use Git's --literal-pathspecs plus "--" and review only the supplied file list.
Ground yourself in CLAUDE.md/AGENTS.md if present, relevant docs, and the code
one dependency hop around each change. Find what is WRONG through the ${p.name}
lens only: ${p.focus}. No praise or summaries. Return concise findings with
severity and file:line.`,
    { label: `review:${p.key}`, schema: FINDINGS })))
return args.perspectives.map((p, i) => ({
  perspective: p.name,
  status: results[i] ? 'completed' : 'failed',
  findings: results[i]?.findings || [],
}))
```

Invoke with `args` carrying the absolute project path, session baseline, verified session file set, and the perspectives chosen for this diff. These perspectives are illustrative:

```js
{ cwd: "/path/to/app", baseline: "<full-commit-sha>",
  sessionFiles: ["app/Team.php", "tests/TeamTest.php"], perspectives: [
  { key: "security",    name: "security",             focus: "..." },
  { key: "performance", name: "performance",          focus: "..." },
  { key: "conventions", name: "framework conventions", focus: "..." } ] }
```

When the review finishes, reconcile the workspace before reading findings. A failed/null reviewer result is a failed perspective, not an empty review; report or rerun it. Verify and deduplicate completed findings against the unchanged code. Report valid findings grouped by perspective and mark anything you could not verify. Then present a fresh picker whose first suggestion(s) turn the highest-severity findings into work. After substantial fixes, you may offer the review again.

## Fallback

The `Workflow` tool requires Claude Code v2.1.154+ and a supported paid or API/provider setup. It can also be disabled in configuration or managed settings. If unavailable, say so and fall back to parallel reviewer subagents through the `Agent` tool. Spawn every reviewer fresh with only the read-only review prompt and grounding instructions. Apply the same pre/post mutation audit to the fallback.

> Workflow approval depends on permission mode. Default and accept-edits modes prompt on each run unless the developer granted persistent project consent. Auto mode normally prompts on the first launch. Bypass-permissions and non-interactive runs do not show the launch prompt.
