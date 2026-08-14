# Vibe Engineering — Session Types

Different types of sessions call for different approaches. Adapt your plan, bootstrapping, and suggestion strategy to match what the developer is trying to accomplish.

## New Feature

Plan the full architecture. Start with the foundation (data layer, core structure) and build up. Suggestions should follow dependency order — migrations before models, models before controllers, controllers before routes, routes before views.

## Update / Enhance Feature

Read the existing implementation first. Plan around what's already there — don't propose rebuilding what works. Start with the change that has the most downstream impact. Suggestions should minimize breaking changes and preserve existing behavior.

## Bug Fix

Run the diagnosis loop in `references/bug-diagnosis.md`. During planning, define the symptom and feedback-loop strategy without claiming a cause. After plan approval, make the red-capable feedback loop the first atomic task before changing production code. The loop's phases map onto the session's atomic steps, and its diagnostic paths map onto the picker. Keep the scope tight — don't refactor while fixing. The grilling for a bug session is short: symptom, expected behavior, when it started, and what changed.

## Refactor

Explain the "why" in the plan — what's wrong with the current approach and what the target state looks like. Establish characterization tests or another observable baseline first when coverage is weak. Then change the smallest seam that reduces the highest risk. Each step must leave the code in a working state — never break things mid-refactor.

## Dependency Upgrade

Check official changelogs, migration guides, compatibility constraints, and the existing baseline checks first. Include findings in the plan. Make the version and lockfile update together with the minimum compatibility edits needed to restore the narrow relevant check; do not leave an intentionally broken intermediate state. Then handle remaining breaking changes one at a time and expand validation toward the full suite.

## Performance Optimization

Profile or identify the bottleneck in the plan. Start with measurement — add benchmarks, logging, or profiling before changing anything. Then suggest the optimization. Then verify the improvement with the same measurement. Don't optimize without measuring first.

## Design / UI

Start with layout and structure before visual polish. Suggestions should flow from skeleton → content → styling → responsive → interaction → animation. Don't suggest color tweaks before the layout is right. Don't suggest animations before the responsive behavior works.

## Security Hardening

Identify the threat surface in the plan. Start with the highest-risk area — authentication, input validation, data exposure. Suggest one fix at a time with clear before/after. Suggest tests that verify the vulnerability is actually closed.

## Documentation

Start with the structure and outline before writing content. Suggest one section at a time. Adapt suggestions to the type — API docs flow differently than user guides or READMEs. Suggest examples and code samples as their own steps, not afterthoughts.
