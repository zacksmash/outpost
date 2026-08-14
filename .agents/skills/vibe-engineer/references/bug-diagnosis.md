# Vibe Engineering — Bug Diagnosis Loop

A discipline for bug-fix sessions (adapted from Matt Pocock's `diagnosing-bugs` skill). Each phase is an atomic step in the normal vibe loop — report it, then present the picker. Skip a phase only when you say why.

**Redact first.** You'll be showing commands and outputs. Replace every secret with `<REDACTED>`; keep credentials in env vars, not in what you show.

## Phase 1 — Build a feedback loop

**This is the whole loop.** Before changing production code or building a theory from deep code reading, produce **one command** — a test, a curl, a CLI invocation, or a headless-browser script — that you have already run, that goes **red on this exact bug** and will go green when it is fixed. Minimal orientation to locate the system boundary is allowed. "Runs without erroring" does not count; the command must assert the user's actual symptom.

Ways to get one, roughly in order: failing test at whatever seam reaches the bug → HTTP script against the dev server → CLI with a fixture input diffed against known-good → Playwright script → replay a captured payload through the code path → minimal throwaway harness → bisection harness if the bug appeared between two known states.

Then tighten it: faster (seconds, not minutes), sharper (assert the specific symptom), deterministic (pin time, seed RNG). For flaky bugs, the goal is a *high reproduction rate*, not a perfect repro — loop the trigger, add stress, narrow timing until it fails often enough to debug.

If you genuinely cannot build one, stop, say so, list what you tried, and ask the developer for a repro environment or captured artifact. Continue with evidence-limited static diagnosis only when the developer explicitly chooses that tradeoff; label every conclusion provisional.

## Phase 2 — Reproduce + minimise

Run the loop, watch it go red, and confirm it's the *user's* failure — not a nearby one. Then shrink the repro: cut inputs, callers, and config one at a time, re-running after each cut, until every remaining element is load-bearing. The minimal repro shrinks the hypothesis space and becomes the regression test later.

## Phase 3 — Hypothesise

Generate **2–4 ranked, falsifiable hypotheses** before testing any ("if X is the cause, changing Y makes it disappear"). If you cannot state the prediction, discard it. Present exactly 4 diagnostic paths in the picker: one per credible hypothesis, then use targeted evidence-gathering paths for any remaining slots. Never invent filler hypotheses. The developer can often re-rank them immediately ("we just deployed a change to #3").

## Phase 4 — Instrument

Each probe maps to one prediction; change one variable at a time. Prefer a debugger/REPL over logs; targeted logs over "log everything and grep". Tag every debug log with a unique prefix (e.g. `[DEBUG-a4f2]`) so cleanup is one grep. For performance bugs, measure a baseline first — logs are usually the wrong tool.

## Phase 5 — Fix + regression test

Promote the Phase 1 feedback loop into a permanent regression test, or write that test now if the original loop was an external harness. Keep it at a seam that exercises the real bug pattern. If no correct seam exists, that is a finding — note it rather than writing a false-confidence test. Then: watch it fail → fix → watch it pass → re-run the Phase 1 loop against the original scenario.

## Phase 6 — Cleanup

Before suggesting "finish": the original repro no longer reproduces, the regression test passes (or the missing seam is documented), all `[DEBUG-...]` logs are removed, throwaway harnesses are deleted or excluded from the session file set, and the root cause is recorded in the Done report and proposed commit summary.
