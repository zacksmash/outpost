# Command Input and Output Design

Date: 2026-08-16
Status: Approved, pending implementation plan

## Problem

Two unrelated defects share one surface — the console commands.

**Instance names mangle branch names.** `OutpostCommand` derives the default
instance name with `Str::slug($branch)`. `Str::slug()` strips `/` rather than
converting it to a separator, so the branch `feature/some-bug-to-fix` becomes
the instance `featuresome-bug-to-fix` and the hostname
`featuresome-bug-to-fix-myapp.outpost`. Every namespaced branch name is
affected.

**Output reads as amateur.** The package speaks three idioms at once: Laravel
Prompts helpers, Laravel's `$this->components->twoColumnDetail`, and raw
`$this->line`. Guidance stacks — `RemoveCommand` emits four boxed blocks in a
row, `DoctorCommand` emits five on every run whether or not anything is wrong.
None of the elements added in Laravel Prompts 0.3.22 are used, though the
package already requires that version.

## Scope

In scope: instance name generation, hostname derivation, and the console
output of all eighteen commands plus the five `Console/Concerns` traits.

Out of scope: the `--json` payloads, which are a machine contract and must
come through this work byte-for-byte identical. Also out of scope: any change
to provisioning, runtime, or manifest behavior beyond the fields naming
touches.

## Naming and Hostnames

### Generated names

Instance names become Forge-style generated adjective-noun pairs —
`blissful-lake`, `quiet-harbor` — replacing branch-derived names. The branch
is no longer an input to the hostname, which makes the slug defect
unreachable on the default path.

A new `Zacksmash\Outpost\Names` class owns this:

- `generate(): string` — an adjective-noun pair drawn from a bundled
  wordlist of roughly 128 adjectives and 128 nouns, giving about 16,000
  combinations. The wordlist ships as a package resource.
- `unique(Outposts $outposts, RuntimeDriver $runtime): string` — regenerates
  while the candidate exists either as a manifest for this application or as
  a container on the machine. Both checks are required: container names are
  machine-wide and the project-directory suffix that used to disambiguate
  them is being removed. Attempts are capped; on exhaustion the class appends
  a numeric suffix rather than looping.
- `normalize(string $name): string` — collapses every run of
  non-alphanumeric characters to a single hyphen before slugging, so
  `feature/some-bug-to-fix` yields `feature-some-bug-to-fix`.

### Where each is used

The generated name becomes the default of the existing `text()` prompt in
`OutpostCommand::name()`, so pressing enter accepts it and typing replaces it.
`--name` continues to override and skip the prompt entirely.

`normalize()` applies to user-supplied names — the `--name` option and typed
prompt input. This is the path where the original defect is still reachable.
A supplied name that changes under normalization is accepted and the result
reported back to the user, rather than rejected. Rejecting `--name
feature/foo` with "the name must be a URL-friendly slug" is worse than
accepting it as `feature-foo` and saying so.

### Hostname shape

The container name becomes the instance name alone. The
`-{project-directory-slug}` suffix at `OutpostCommand:202` is removed, so the
URL is `https://blissful-lake.outpost`.

This deletes a failure class. `invalidContainer()` currently errors when the
project directory contains no URL-friendly characters; the directory no
longer feeds the hostname, so that branch goes away. The 63-character DNS
label check stays, now reachable only through a very long `--name`.

### Compatibility

No migration. `Manifest` persists `container` and `url` explicitly, and
existing values such as `feature-billing-myapp` remain valid slugs, so
existing instances keep their hostnames and keep working. Only newly created
instances take the new shape. `Outposts` validation is unchanged.

## Output Vocabulary

The governing rule: **one terminal callout per command run, and guidance never
stacks.** Multi-line guidance belongs inside a single callout as a
`BulletedList` or `KeyValueList`, never as consecutive `note()` calls.

| Element | Reserved for |
| --- | --- |
| `callout()` | The outcome of a command. One per run, at the end. Replaces terminal `outro()` wherever there is structured detail. |
| `callout()` types | Only `'error'` (red, `⚠` prefix), `'warning'` (yellow, `⚠` prefix), and `null` (cyan, no prefix) exist. There is no `'success'` type; a successful outcome passes `null`. Any other string silently renders as the default, so passing `'success'` is a latent bug. |
| `table()` | Pipeable inventories — `outpost:list`, `outpost:doctor`. Never blocks. |
| `datatable()` | The instance picker only, behind an interactivity guard. |
| `task()` | Long operations worth a kept summary, such as provisioning steps. |
| `spin()` | Long operations with nothing to report — boot, checkout, certificate creation. |
| `note()` | A single contextual hint. Never two in sequence. |
| `KeyValueList` | Detail pairs. Replaces `twoColumnDetail` in `outpost:info`. |
| `progress()` | Multi-instance loops in `outpost:upgrade`. |

`title()` is deliberately absent: it sets the terminal window title via an OSC
escape and renders nothing visible.

`form()` is deliberately absent. `outpost:install` computes a plan, shows it,
takes one confirmation, and executes. Prompts' `form()` is a sequential
multi-prompt builder with back-navigation, which does not fit a single
confirmation. Install instead renders its action list as a `callout`
containing a `BulletedList`, replacing the hand-rolled
`"\n\n  • ".implode(...)` inside a `note()` at `InstallCommand:77`.

### Shared concern

`RendersJsonOutput` grows into `RendersOutput`, holding only branching
decisions, not wrappers around rendering:

```php
protected function canPrompt(): bool;                                       // interactive && ! json
protected function renderError(string $message, array $remedy = []): void;  // remedy folds into the callout
protected function renderSummary(string $label, array $content, ?string $type = null, string $info = ''): void;
```

Call sites continue to call `callout()`, `table()`, and `task()` directly, so
the code still reads as Laravel Prompts to a Laravel developer.

### Interactivity

`datatable()` is a picker, not a display element: it blocks until a row is
selected, and in non-interactive mode `Prompt::prompt()` returns `default()`
without rendering. Every use requires an explicit guard through
`canPrompt()`; the automatic fallback is not sufficient.

The picker replaces the bare `select('Which instance?')` in
`ResolvesInstances`, showing Name, Branch, State, and URL. This matters more
under generated names, since a list of bare names like `blissful-lake` carries
no information about which branch it runs.

`outpost:list` stays a plain `table()` in all contexts, so it remains safe to
pipe.

### Conditional guidance

`DoctorCommand`'s three standing `note()` blocks — host access, container
probes, service endpoints — become conditional on a failing check or on `-v`.
A healthy `outpost:doctor` prints a table and a one-line pass, nothing more.

## Implementation Steps

Steps 1 and 2 are independent of 3 through 8, so the naming fix lands and is
verifiable before any output churn begins. Each step carries its own tests;
there is no trailing step to repair tests.

1. `Names` class, wordlist, and `normalize()`. New `src/Names.php` and
   `tests/Unit/NamesTest.php`.
2. Wire naming into instance creation. `OutpostCommand` at `:202`, `:442`,
   `:453`, and `:478-489`.
3. `RendersJsonOutput` becomes `RendersOutput`. Concern only; no command
   changes.
4. Datatable instance picker. `ResolvesInstances:44-70`.
5. Diagnostics output. `DoctorCommand`, `InstallCommand`, `VerifyCommand`.
6. Lifecycle output. `OutpostCommand`, `StartCommand`, `StopCommand`,
   `RemoveCommand`, `UpgradeCommand`, `RebuildsInstanceContainers`.
7. Inspection output. `ListCommand`, `InfoCommand`, dropping
   `twoColumnDetail`.
8. Remaining commands. `LogsCommand`, `ShellCommand`, `ExecCommand`,
   `OpenCommand`, `PullCommand`, `BuildCommand`, `CertifyCommand`,
   `ProcessCommand`.
9. Documentation and the bundled Boost skill. `README.md` and
   `resources/boost/skills` via the `package-generate-skill` skill.

## Testing

Test-driven, Pest, behavior observed through public APIs, per the
`package-testing` skill.

**`Names` is unit tested directly.** `normalize()` gets a case table covering
`feature/x`, `release/v2.1.0`, `ZACK_fix.thing`, unicode input, and the empty
string. Uniqueness is tested against a fake `Outposts` and `RuntimeDriver`
that report collisions, asserting both regeneration and the numeric-suffix
fallback on exhaustion.

**Generated names are made deterministic in feature tests.** A random name
would make assertions non-deterministic, so `Names` is bound in the container
and replaced with a deterministic fake in `TestCase`. Feature tests continue
asserting against a known name.

**Output assertions move from `expectsOutput` to `expectsOutputToContain`**
on the semantic payload — the URL, the instance name, the remedy text. Box
drawing, padding, and wrapping are Prompts' concern, not this package's, and
asserting on them is what makes the current 161 assertions across 16 files
brittle.

**House-style invariants live in `CommandExperienceTest`**, which already pins
command descriptions and option wording. Added assertions, evaluated over the
command sources so they hold for commands added later:

- No two consecutive `note()` calls in any command.
- Exactly one terminal callout per command path.
- No remaining use of `twoColumnDetail`.

**Non-interactive behavior is covered explicitly.** The datatable picker must
never engage when stdin is not a TTY, and `--json` output must be unchanged
byte-for-byte by any part of this work.

The existing gate is unchanged: `composer test` runs PHPStan, Pint, 100% type
coverage, then Pest.
