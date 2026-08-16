# Command Output Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give all eighteen commands one output vocabulary built on Laravel Prompts 0.3.22, replacing stacked `note()` blocks, `twoColumnDetail`, and raw `$this->line()` with a single terminal callout per run.

**Architecture:** `RendersJsonOutput` becomes `RendersOutput`, holding only branching decisions — JSON versus human, interactive versus not, and the shape of an error or summary. Individual commands keep calling `callout()`, `table()`, `task()`, and `spin()` directly, so call sites still read as Laravel Prompts. The dominant mechanical change is folding each `error(...)` plus trailing `note(...)` remedy pair into one `renderError($message, $remedy)` call.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Laravel Prompts 0.3.22, Pest 4/5, Orchestra Testbench 10/11, PHPStan level 7 via Larastan.

**Spec:** `docs/superpowers/specs/2026-08-16-command-io-design.md`

**Independent of** `docs/superpowers/plans/2026-08-16-instance-naming.md`. Either may land first. Where this plan shows expected output containing an instance name, it uses `feature-billing`, which both plans produce.

## Global Constraints

- Namespace is `Zacksmash\Outpost\`; PSR-4 root is `src/`.
- Every PHP file starts with `declare(strict_types=1);`.
- PHPStan level 7 over `src` and `config` must pass: `composer analyse`.
- Pint must pass: `composer lint:check`.
- Type coverage must stay at 100%: `composer test:types`.
- **`--json` output is a machine contract and must be unchanged byte-for-byte.** `writeJson()` and every `--json` code path stay exactly as they are.
- **`callout()` accepts only three type values:** `'error'` (red, `⚠` prefix), `'warning'` (yellow, `⚠` prefix), and `null` (cyan, no prefix). There is no `'success'` type — a successful outcome passes `null`. Any other string silently renders as the default, so never pass one.
- `title()` is never used: it emits an OSC terminal-window-title escape and renders nothing visible.
- `form()` is never used: `outpost:install` is a plan-then-confirm flow, not a multi-step questionnaire.
- **The governing rule: one terminal callout per command run, and guidance never stacks.** Two consecutive `note()` or `warning()` calls are a defect. Multi-line guidance goes inside one callout as a `BulletedList` or `KeyValueList`.
- `datatable()` blocks for a selection and returns `default()` without rendering when stdin is not a TTY. Every use needs an explicit `canPrompt()` guard; the automatic fallback is not sufficient.
- Output assertions use `expectsOutputToContain()` on the semantic payload, never `expectsOutput()` on a full formatted line. Box drawing and wrapping belong to Prompts.

---

## File Structure

**Create:**
- `src/Console/Concerns/RendersOutput.php` — the decision logic. Replaces `RendersJsonOutput.php`.
- `tests/Feature/Commands/RendersOutputTest.php` — direct coverage of the concern.

**Delete:**
- `src/Console/Concerns/RendersJsonOutput.php` — after every consumer moves.

**Modify — concerns:**
- `src/Console/Concerns/ResolvesInstances.php:44-70` — datatable picker.
- `src/Console/Concerns/RebuildsInstanceContainers.php:44,101,109-115,226,241-242`
- `src/Console/Concerns/ResolvesPathRepositoryMounts.php:31,47,68-71,90,99`
- `src/Console/Concerns/FlushesDnsCaches.php:27-28`

**Modify — commands:** `Doctor`, `Install`, `Verify`, `Outpost`, `Start`, `Stop`, `Remove`, `Upgrade`, `List`, `Info`, `Logs`, `Shell`, `Exec`, `Open`, `Pull`, `Build`, `Certify`, `Process`.

**Modify — tests:** `tests/Feature/Commands/CommandExperienceTest.php` plus the sixteen command test files carrying 161 output assertions.

---

## Task 1: The RendersOutput concern

**Files:**
- Create: `src/Console/Concerns/RendersOutput.php`
- Test: `tests/Feature/Commands/RendersOutputTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces, all `protected`:
  - `wantsJsonOutput(): bool` — carried over unchanged
  - `writeJson(array $payload): void` — carried over unchanged
  - `canPrompt(): bool`
  - `renderError(string $message, array $remedy = []): void`
  - `renderWarning(string $label, array $content = []): void`
  - `renderSummary(string $label, array $content = [], ?string $type = null, string $info = ''): void`

`$remedy` and `$content` are `list<string|ElementContract>` — the exact type `callout()` accepts for its content parts.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/RendersOutputTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\Elements\BulletedList;
use Zacksmash\Outpost\Console\Concerns\RendersOutput;

beforeEach(function () {
    Artisan::command('outpost-test:renders {--json} {--fail}', function () {
        /** @var Command $this */
        if ($this->option('fail')) {
            $this->renderError('It broke.', ['Try turning it off and on again.']);

            return Command::FAILURE;
        }

        $this->renderSummary('All good', [new BulletedList(['One thing'])], info: 'feature-billing');

        return Command::SUCCESS;
    });
});

it('renders an error and its remedy inside one callout', function () {
    $this->artisan('outpost-test:renders --fail')
        ->expectsOutputToContain('It broke.')
        ->expectsOutputToContain('Try turning it off and on again.')
        ->assertFailed();
});

it('renders an error as a machine-readable document under json', function () {
    $this->artisan('outpost-test:renders --fail --json')
        ->expectsOutputToContain('"error": "It broke."')
        ->doesntExpectOutputToContain('Try turning it off and on again.')
        ->assertFailed();
});

it('renders a summary callout with its content and info label', function () {
    $this->artisan('outpost-test:renders')
        ->expectsOutputToContain('All good')
        ->expectsOutputToContain('One thing')
        ->expectsOutputToContain('feature-billing')
        ->assertSuccessful();
});

it('refuses to prompt when output is machine readable', function () {
    $command = new class extends Command
    {
        use RendersOutput;

        protected $signature = 'outpost-test:can-prompt {--json}';

        public function handle(): int
        {
            return $this->canPrompt() ? self::SUCCESS : self::FAILURE;
        }
    };

    Artisan::registerCommand($command);

    $this->artisan('outpost-test:can-prompt --json')->assertFailed();
});
```

Note: the `--fail --json` case asserts the remedy is *absent*, because a JSON consumer must receive the existing single-key error document and nothing else.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Commands/RendersOutputTest.php`

Expected: FAIL with `Trait "Zacksmash\Outpost\Console\Concerns\RendersOutput" not found`.

- [ ] **Step 3: Write the concern**

Create `src/Console/Concerns/RendersOutput.php`:

```php
<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Concerns;

use Illuminate\Console\Command;
use JsonException;
use Laravel\Prompts\Elements\ElementContract;

use function Laravel\Prompts\callout;

/**
 * @mixin Command
 */
trait RendersOutput
{
    /**
     * Determine whether this command requested machine-readable output.
     */
    protected function wantsJsonOutput(): bool
    {
        return $this->hasOption('json') && (bool) $this->option('json');
    }

    /**
     * Determine whether this command may put a prompt on screen.
     *
     * A blocking prompt has nobody to answer it without a terminal, and it
     * would corrupt a machine-readable document, so both are refused.
     */
    protected function canPrompt(): bool
    {
        return $this->input->isInteractive() && ! $this->wantsJsonOutput();
    }

    /**
     * Write one consistently formatted JSON document.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws JsonException
     */
    protected function writeJson(array $payload): void
    {
        $this->line(json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Render a human error or a parseable JSON error document.
     *
     * The remedy folds into the same callout rather than trailing after it
     * as a separate note, so guidance never stacks.
     *
     * @param  list<string|ElementContract>  $remedy
     */
    protected function renderError(string $message, array $remedy = []): void
    {
        if ($this->wantsJsonOutput()) {
            $this->writeJson(['error' => $message]);

            return;
        }

        callout($message, $remedy, 'error');
    }

    /**
     * Render a warning and its context as one callout.
     *
     * @param  list<string|ElementContract>  $content
     */
    protected function renderWarning(string $label, array $content = []): void
    {
        if ($this->wantsJsonOutput()) {
            return;
        }

        callout($label, $content, 'warning');
    }

    /**
     * Render the outcome of a command as its single terminal callout.
     *
     * A null type is the neutral, successful style. Only 'error' and
     * 'warning' are otherwise meaningful; any other value renders as null.
     *
     * @param  list<string|ElementContract>  $content
     */
    protected function renderSummary(
        string $label,
        array $content = [],
        ?string $type = null,
        string $info = '',
    ): void {
        if ($this->wantsJsonOutput()) {
            return;
        }

        callout($label, $content, $type, $info);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Commands/RendersOutputTest.php`

Expected: PASS.

- [ ] **Step 5: Point every existing consumer at the new trait**

Run: `grep -rln 'RendersJsonOutput' src/ tests/`

Expected consumers: `src/Console/Concerns/ResolvesInstances.php`, `src/Console/Commands/ListCommand.php`, `src/Console/Commands/DoctorCommand.php`. In each, change both the `use Zacksmash\Outpost\Console\Concerns\RendersJsonOutput;` import and the in-class `use RendersJsonOutput;` statement to `RendersOutput`.

- [ ] **Step 6: Delete the old trait**

```bash
git rm src/Console/Concerns/RendersJsonOutput.php
```

- [ ] **Step 7: Run the full gate**

Run: `composer test`

Expected: all green. `--json` behavior is untouched, so no `--json` assertion should move.

- [ ] **Step 8: Commit**

```bash
git add src tests
git commit -m "refactor: consolidate console output decisions into RendersOutput"
```

---

## Task 2: The datatable instance picker

**Files:**
- Modify: `src/Console/Concerns/ResolvesInstances.php:44-70`
- Test: `tests/Feature/Commands/InfoCommandTest.php`

**Interfaces:**
- Consumes: `canPrompt()` from Task 1; `RuntimeDriver::instanceState(Manifest $manifest, ?string $runtimeState = null): string`, which already exists.
- Produces: unchanged public behavior — `instance(Outposts $outposts): ?Manifest` and `instanceName(Outposts $outposts): ?string` keep their signatures.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/InfoCommandTest.php`:

```php
it('shows branch and state alongside the name when picking an instance', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-billing'));

    $this->artisan('outpost:info')
        ->expectsOutputToContain('feature/billing')
        ->assertSuccessful();
});

it('never puts a picker on screen when output is machine readable', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-billing'));

    $this->artisan('outpost:info --json')
        ->expectsOutputToContain('"error": "An instance name is required when using --json."')
        ->assertFailed();
});
```

- [ ] **Step 2: Run the tests to verify the first fails**

Run: `vendor/bin/pest tests/Feature/Commands/InfoCommandTest.php`

Expected: the branch assertion FAILS — the current `select()` renders bare names only. The `--json` case already passes and is here as a regression guard.

- [ ] **Step 3: Replace the picker**

In `src/Console/Concerns/ResolvesInstances.php`, swap the import:

```php
use function Laravel\Prompts\datatable;
```

for the existing `use function Laravel\Prompts\select;`, add `use Zacksmash\Outpost\Contracts\RuntimeDriver;`, and replace the tail of `instanceName()` from `$names = array_map(...)` through `return (string) select(...)`:

```php
        $manifests = $outposts->all();

        if ($manifests === []) {
            $this->renderError('No instances exist yet. Create one with [php artisan outpost].');

            return null;
        }

        if (! $this->canPrompt()) {
            $this->renderError('An instance name is required without an interactive terminal.');

            return null;
        }

        $runtime = $this->laravel->make(RuntimeDriver::class);
        $states = $runtime->states();

        $selected = datatable(
            headers: ['Name', 'Branch', 'State', 'URL'],
            rows: array_map(fn (Manifest $manifest): array => [
                $manifest->name,
                $manifest->branch,
                $runtime->instanceState($manifest, $states[$manifest->container] ?? 'missing'),
                $manifest->url,
            ], $manifests),
            label: 'Which outpost?',
            hint: 'Type to filter.',
            required: true,
        );

        return is_array($selected) && isset($selected[0]) && is_string($selected[0])
            ? $selected[0]
            : null;
```

The `--json` guard already sits above this block at `:53-57` and stays where it is, so a `--json` run still returns the existing error message rather than the new non-interactive one.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/InfoCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Run the commands that share the picker**

Run: `vendor/bin/pest tests/Feature/Commands --filter="Info|Logs|Shell|Exec|Open|Start|Stop|Remove|Verify|Process"`

Expected: PASS. Any test that answered the old picker with `expectsQuestion('Which instance?', ...)` must move to passing the name as an argument, because a datatable is a row selection rather than a text answer.

- [ ] **Step 6: Commit**

```bash
git add src/Console/Concerns/ResolvesInstances.php tests
git commit -m "feat: pick instances from a table showing branch and state"
```

---

## Task 3: outpost:doctor

**Files:**
- Modify: `src/Console/Commands/DoctorCommand.php:70-98`
- Test: `tests/Feature/Commands/DoctorCommandTest.php`

**Interfaces:**
- Consumes: `renderSummary()` and `renderError()` from Task 1.
- Produces: no new interfaces.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/DoctorCommandTest.php`:

```php
it('prints nothing but the table and a one line pass when healthy', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->andReturn([
        DoctorCheck::pass(Doctor::RUNTIME_CHECK, 'The Apple container system is running.'),
    ]);
    app()->instance(Doctor::class, $doctor);

    $this->artisan('outpost:doctor')
        ->expectsOutputToContain('Outpost is ready')
        ->doesntExpectOutputToContain('Host access')
        ->doesntExpectOutputToContain('Container probes')
        ->doesntExpectOutputToContain('Service web endpoints')
        ->assertSuccessful();
});

it('surfaces troubleshooting guidance once a check fails', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->andReturn([
        DoctorCheck::failure(Doctor::RUNTIME_CHECK, 'The system is stopped.', 'Start it.'),
    ]);
    app()->instance(Doctor::class, $doctor);

    $this->artisan('outpost:doctor')
        ->expectsOutputToContain('Start it.')
        ->expectsOutputToContain('Host access')
        ->assertFailed();
});

it('surfaces troubleshooting guidance on demand when healthy', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->andReturn([
        DoctorCheck::pass(Doctor::RUNTIME_CHECK, 'The Apple container system is running.'),
    ]);
    app()->instance(Doctor::class, $doctor);

    $this->artisan('outpost:doctor -v')
        ->expectsOutputToContain('Host access')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Commands/DoctorCommandTest.php`

Expected: the healthy case FAILS on `doesntExpectOutputToContain('Host access')` — the three guidance blocks currently print unconditionally.

- [ ] **Step 3: Replace the output tail**

In `src/Console/Commands/DoctorCommand.php`, replace everything from the `table(` call to the end of `handle()`:

```php
        table(
            ['Status', 'Check', 'Details'],
            array_map(fn (DoctorCheck $check): array => [
                $check->status,
                $check->name,
                $check->detail,
            ], $checks),
        );

        $remedies = array_values(array_filter(array_map(
            fn (DoctorCheck $check): ?string => $check->remedy === null
                ? null
                : "{$check->name}: {$check->remedy}",
            [...$failures, ...$warnings],
        )));

        if ($failures !== [] || $warnings !== [] || $this->output->isVerbose()) {
            $this->renderTroubleshooting();
        }

        if ($failures !== []) {
            $this->renderError(
                sprintf('Outpost found %d blocking issue%s.', count($failures), count($failures) === 1 ? '' : 's'),
                $remedies === [] ? [] : [new BulletedList($remedies)],
            );

            return self::FAILURE;
        }

        if ($warnings !== []) {
            $this->renderWarning(
                sprintf('Outpost is ready with %d warning%s.', count($warnings), count($warnings) === 1 ? '' : 's'),
                $remedies === [] ? [] : [new BulletedList($remedies)],
            );

            return self::SUCCESS;
        }

        $this->renderSummary('Outpost is ready.', [], info: sprintf(
            '%d check%s passed',
            count($checks),
            count($checks) === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * Render the standing troubleshooting guidance as one callout.
     *
     * This is long, so it appears only when something needs fixing or when
     * the operator asks for it with -v.
     */
    protected function renderTroubleshooting(): void
    {
        if ($this->wantsJsonOutput()) {
            return;
        }

        callout('Troubleshooting', [
            new Heading('Host access'),
            'If browsers or CLI tools cannot reach container addresses, enable the calling application under System Settings > Privacy & Security > Local Network.',
            new Heading('Container probes'),
            'The published hostname does not resolve inside its own container.',
            new BulletedList([
                'php artisan outpost:exec <name> -- curl --fail --silent --show-error http://localhost',
                'php artisan outpost:exec <name> -- curl --fail --silent --show-error --insecure https://localhost',
            ]),
            new Heading('Service endpoints'),
            'Service web endpoints use the same scheme as the application. Copy host URLs from [php artisan outpost:info <name>].',
            new BulletedList([
                'php artisan outpost:exec <name> -- curl --fail --silent --show-error http://localhost:8025',
                'php artisan outpost:exec <name> -- curl --fail --silent --show-error --insecure https://localhost:8025',
            ]),
        ]);
    }
```

Update the imports: drop `use function Laravel\Prompts\error;`, `note;`, `outro;`, and `warning;`; add `use function Laravel\Prompts\callout;`, `use Laravel\Prompts\Elements\BulletedList;`, and `use Laravel\Prompts\Elements\Heading;`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/DoctorCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Confirm the JSON contract is untouched**

Run: `vendor/bin/pest tests/Feature/Commands/DoctorCommandTest.php --filter=json`

Expected: PASS with no assertion changes. The `--json` branch returns before any of this code.

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/DoctorCommand.php tests/Feature/Commands/DoctorCommandTest.php
git commit -m "feat: make doctor guidance conditional and its result one callout"
```

---

## Task 4: outpost:install

**Files:**
- Modify: `src/Console/Commands/InstallCommand.php:55-58`, `:77`, `:118-121`, `:161-199`
- Test: `tests/Feature/Commands/InstallCommandTest.php`

**Interfaces:**
- Consumes: `renderError()`, `renderWarning()`, `renderSummary()` from Task 1.
- Produces: `finish(array $checks, bool $declined = false): int` keeps its signature.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/InstallCommandTest.php`:

```php
it('renders the setup plan as one callout rather than a hand rolled bullet string', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->andReturn([
        DoctorCheck::failure(Doctor::RUNTIME_CHECK, 'The system is stopped.', 'Start it.'),
    ]);
    app()->instance(Doctor::class, $doctor);

    $this->artisan('outpost:install')
        ->expectsOutputToContain('Outpost will prepare this Mac')
        ->expectsOutputToContain('Start the Apple container system')
        ->doesntExpectOutputToContain('  • ')
        ->expectsConfirmation('Prepare Outpost now?', 'no')
        ->assertFailed();
});

it('reports remaining setup steps in a single error callout', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->andReturn([
        DoctorCheck::failure(Doctor::RUNTIME_CHECK, 'The system is stopped.', 'Start it.'),
        DoctorCheck::failure(Doctor::DNS_RESOLVER_CHECK, 'DNS is unregistered.', 'Register it.'),
    ]);
    app()->instance(Doctor::class, $doctor);

    $this->artisan('outpost:install')
        ->expectsConfirmation('Prepare Outpost now?', 'no')
        ->expectsOutputToContain('Start it.')
        ->expectsOutputToContain('Register it.')
        ->assertFailed();
});
```

`doesntExpectOutputToContain('  • ')` is the assertion that pins the fix: the hand-rolled two-space-bullet string must be gone, replaced by the renderer's own bullet.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Commands/InstallCommandTest.php --filter="one callout"`

Expected: FAIL on `doesntExpectOutputToContain('  • ')`.

- [ ] **Step 3: Replace the plan note**

In `src/Console/Commands/InstallCommand.php`, replace line 77:

```php
            note("Outpost will prepare this Mac:\n\n  • ".implode("\n  • ", $actions));
```

with:

```php
            callout('Outpost will prepare this Mac', [new BulletedList($actions)]);
```

- [ ] **Step 4: Replace the paired notes at :55-58**

```php
        if ($this->option('local') && $this->passed($checks, Doctor::BASE_IMAGE_CHECK)) {
            $this->renderWarning('The configured image is already compatible, so --local is not rebuilding it.', [
                new BulletedList(['php artisan outpost:build --force']),
            ]);
        }
```

- [ ] **Step 5: Replace the catch block and the admin-password note**

At `:118-121`, the note before `registerDomain()` is a single contextual hint with nothing stacked after it, so it stays a `note()`. At `:153-157`, replace `error($e->getMessage());` with:

```php
            $this->renderError($e->getMessage());
```

- [ ] **Step 6: Rewrite finish()**

Replace `finish()` at `:161-199`:

```php
    protected function finish(array $checks, bool $declined = false): int
    {
        $failures = array_values(array_filter(
            $checks,
            fn (DoctorCheck $check): bool => $check->status === DoctorCheck::FAIL,
        ));

        $remedies = array_values(array_filter(array_map(
            fn (DoctorCheck $check): ?string => $check->remedy === null
                ? null
                : "{$check->name}: {$check->remedy}",
            $failures,
        )));

        if ($declined) {
            $this->renderError('Outpost setup was not changed.', [
                ...($remedies === [] ? [] : [new BulletedList($remedies)]),
                'Run [php artisan outpost:install] when you are ready.',
            ]);

            return self::FAILURE;
        }

        if ($failures !== []) {
            $this->renderError(
                sprintf('Outpost still needs %d setup step%s.', count($failures), count($failures) === 1 ? '' : 's'),
                [
                    ...($remedies === [] ? [] : [new BulletedList($remedies)]),
                    'Run [php artisan outpost:install] again when you are ready, or [php artisan outpost:doctor] for the full report.',
                ],
            );

            return self::FAILURE;
        }

        $this->renderSummary('Outpost is ready.', [
            'Create an instance with [php artisan outpost].',
        ]);

        return self::SUCCESS;
    }
```

Add `use Zacksmash\Outpost\Console\Concerns\RendersOutput;` and the in-class `use RendersOutput;`, plus `use Laravel\Prompts\Elements\BulletedList;` and `use function Laravel\Prompts\callout;`. Drop the now-unused `error` and `outro` imports.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/InstallCommandTest.php`

Expected: PASS. The 24 existing assertions in this file that use `expectsOutput()` on full strings need converting to `expectsOutputToContain()` on the semantic payload.

- [ ] **Step 8: Commit**

```bash
git add src/Console/Commands/InstallCommand.php tests/Feature/Commands/InstallCommandTest.php
git commit -m "feat: render install plan and outcome as callouts"
```

---

## Task 5: outpost:verify

**Files:**
- Modify: `src/Console/Commands/VerifyCommand.php:76-90`
- Test: `tests/Feature/Commands/VerifyCommandTest.php`

**Interfaces:**
- Consumes: `renderSummary()`, `renderError()` from Task 1.
- Produces: no new interfaces.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/VerifyCommandTest.php`:

```php
it('reports a passing verification as one callout carrying the url', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-billing'));

    fakeVerification();

    $this->artisan('outpost:verify', ['name' => 'feature-billing'])
        ->expectsOutputToContain('feature-billing')
        ->expectsOutputToContain('http://feature-billing-app.outpost')
        ->assertSuccessful();
});
```

If `fakeVerification()` does not already exist in this test file, reuse whatever process-faking helper the file's existing passing test uses; do not introduce a new one.

- [ ] **Step 2: Run the test to verify it passes or fails**

Run: `vendor/bin/pest tests/Feature/Commands/VerifyCommandTest.php`

Expected: PASS already — `outro("Verified [{$manifest->name}]: {$manifest->url}")` contains both strings. This test is the regression guard that the payload survives the reformat in step 3.

- [ ] **Step 3: Replace the outcome calls**

Replace `:86`:

```php
                    outro("Verified [{$manifest->name}]: {$manifest->url}");
```

with:

```php
                    $this->renderSummary('Verification passed', [
                        new Link($manifest->url),
                    ], info: $manifest->name);
```

Replace `:88`:

```php
                    error("Verification failed for [{$manifest->name}].");
```

with:

```php
                    $this->renderError("Verification failed for [{$manifest->name}].", [
                        "Inspect the failures above, then check logs with [php artisan outpost:logs {$manifest->name}].",
                    ]);
```

Add `use Laravel\Prompts\Elements\Link;`. `VerifyCommand` already uses `ResolvesInstances`, which pulls in `RendersOutput` via Task 1, so no extra trait import is needed.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Commands/VerifyCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Console/Commands/VerifyCommand.php tests/Feature/Commands/VerifyCommandTest.php
git commit -m "feat: render verification outcome as a callout"
```

---

## Task 6: outpost, outpost:start, outpost:stop

**Files:**
- Modify: `src/Console/Commands/OutpostCommand.php:222-230`, `:339-361`, `:367-376`
- Modify: `src/Console/Commands/StartCommand.php:80`, `:119`, `:128-131`, `:143-147`, `:152`
- Modify: `src/Console/Commands/StopCommand.php:71-90`
- Test: the matching three test files

**Interfaces:**
- Consumes: `renderSummary()`, `renderError()` from Task 1.
- Produces: no new interfaces.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Commands/OutpostCommandTest.php`:

```php
it('summarises a created instance in one callout with its url and detection', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-billing'])
        ->expectsOutputToContain('feature-billing')
        ->expectsOutputToContain('http://feature-billing.outpost')
        ->expectsOutputToContain('PHP')
        ->assertSuccessful();
});
```

Append to `tests/Feature/Commands/StartCommandTest.php`:

```php
it('folds the failure remedy into the error callout', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-billing'));

    fakeStartThatNeverAnswers();

    $this->artisan('outpost:start', ['name' => 'feature-billing'])
        ->expectsOutputToContain('did not answer')
        ->expectsOutputToContain('php artisan outpost:logs feature-billing')
        ->assertFailed();
});
```

Reuse whichever process-faking helper this file's existing timeout test uses rather than adding a new one; rename the call above to match it.

- [ ] **Step 2: Run the tests to verify they pass on payload**

Run: `vendor/bin/pest tests/Feature/Commands/OutpostCommandTest.php tests/Feature/Commands/StartCommandTest.php`

Expected: the new assertions PASS on the current code, since the same strings appear across the current `info()` plus `outro()` pair. They are regression guards for the reformat.

- [ ] **Step 3: Convert OutpostCommand's detection line and outcome**

Replace the `info(sprintf(...))` block at `:222-230` and the `outro(...)` at `:359` with one terminal callout. Delete the `info()` block entirely and place its content in the summary. At `:355-359`, replace:

```php
        if ($manifest->services !== []) {
            note("Inspect service URLs and credentials with:\n\n  php artisan outpost:info {$manifest->name}");
        }

        outro("Created [{$manifest->name}]: {$manifest->url}");
```

with:

```php
        $this->renderSummary('Instance ready', [
            new KeyValueList([
                'URL' => $manifest->url,
                'Branch' => $manifest->branch,
                'Runtime' => "PHP {$manifest->php} / FPM",
                'Resources' => "{$resources['cpus']} CPU / {$resources['memory']}",
                'Frontend' => ucfirst($detection->frontend),
                'Services' => $detection->services === [] ? 'none' : implode(', ', $detection->services),
                'Processes' => $commands === [] ? 'none' : implode(', ', array_keys($commands)),
            ]),
            ...($manifest->services === [] ? [] : [
                "Service URLs and credentials: [php artisan outpost:info {$manifest->name}]",
            ]),
        ], info: $manifest->name);
```

`$resources`, `$detection`, and `$commands` are all in scope at that point in `handle()`.

- [ ] **Step 4: Convert the OutpostCommand failure paths**

At `:339-343` replace `error($e->getMessage());` with `$this->renderError($e->getMessage());`. In `preserveFailedInstance()` at `:375`, replace the `note(...)` with:

```php
        $this->renderWarning('Everything created so far was left in place for debugging.', [
            new BulletedList(["php artisan outpost:remove {$manifest->name}"]),
        ]);
```

Add the trait: `use Zacksmash\Outpost\Console\Concerns\RendersOutput;` plus in-class `use RendersOutput;`, and the imports `use Laravel\Prompts\Elements\BulletedList;` and `use Laravel\Prompts\Elements\KeyValueList;`.

- [ ] **Step 5: Convert StartCommand**

Replace `:128-131`:

```php
                error("The instance started but did not answer within {$seconds} seconds.");
                note("Check its logs with:\n\n  php artisan outpost:logs {$manifest->name}");
```

with:

```php
                $this->renderError("The instance started but did not answer within {$seconds} seconds.", [
                    new BulletedList(["php artisan outpost:logs {$manifest->name}"]),
                ]);
```

Replace `:143-147` the same way, folding the trailing `note()` into `renderError()`'s remedy. Replace the two `outro()` calls at `:119` and `:152` with:

```php
        $this->renderSummary('Instance started', [new Link($manifest->url)], info: $manifest->name);
```

Leave the `info()` at `:80` as-is: it is a single already-running notice with nothing stacked after it.

- [ ] **Step 6: Convert StopCommand**

Replace `outro("Stopped [{$manifest->name}].");` with:

```php
        $this->renderSummary('Instance stopped', [], info: $manifest->name);
```

Replace `error($e->getMessage());` with `$this->renderError($e->getMessage());`. Leave both `info()` calls: each is a standalone already-stopped notice.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/OutpostCommandTest.php tests/Feature/Commands/StartCommandTest.php tests/Feature/Commands/StopCommandTest.php`

Expected: PASS. Convert every `expectsOutput()` in these three files to `expectsOutputToContain()`.

- [ ] **Step 8: Commit**

```bash
git add src/Console/Commands/OutpostCommand.php src/Console/Commands/StartCommand.php src/Console/Commands/StopCommand.php tests
git commit -m "feat: render lifecycle outcomes as callouts"
```

---

## Task 7: outpost:remove

**Files:**
- Modify: `src/Console/Commands/RemoveCommand.php:96-101`, `:115-119`, `:129-136`, `:186-220`, `:244-266`
- Test: `tests/Feature/Commands/RemoveCommandTest.php`

**Interfaces:**
- Consumes: `renderError()`, `renderWarning()`, `renderSummary()` from Task 1.
- Produces: no new interfaces.

This is the worst offender: `:98-101` emits four consecutive boxes.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/RemoveCommandTest.php`:

```php
it('states every consequence of forgetting an instance in one callout', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-billing'));

    $this->artisan('outpost:remove', [
        'name' => 'feature-billing',
        '--forget' => true,
        '--force' => true,
    ])
        ->expectsOutputToContain('Teardown hooks will not run')
        ->expectsOutputToContain('feature-billing-app')
        ->expectsOutputToContain('container delete --force feature-billing-app')
        ->assertSuccessful();
});

it('explains an uncommitted worktree and how to override in one callout', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-billing'));

    fakeRemovalWithDirtyWorktree();

    $this->artisan('outpost:remove', ['name' => 'feature-billing', '--force' => true])
        ->expectsOutputToContain('uncommitted changes')
        ->expectsOutputToContain('--discard-changes')
        ->assertFailed();
});
```

Reuse this file's existing dirty-worktree process fake and rename the call to match it.

- [ ] **Step 2: Run the tests to verify they pass on payload**

Run: `vendor/bin/pest tests/Feature/Commands/RemoveCommandTest.php --filter="one callout"`

Expected: PASS on payload. They are the guard that all four messages survive collapsing into one box.

- [ ] **Step 3: Collapse the forget warning**

Replace `:98-101`:

```php
                warning("Forgetting [{$manifest->name}] without contacting the runtime.");
                note('Teardown hooks will not run.');
                note("Container [{$manifest->container}] may remain.");
                note("Remove it later with [container delete --force {$manifest->container}].");
```

with:

```php
                $this->renderWarning("Forgetting [{$manifest->name}] without contacting the runtime.", [
                    'Teardown hooks will not run.',
                    "Container [{$manifest->container}] may remain. Remove it later with:",
                    new BulletedList(["container delete --force {$manifest->container}"]),
                ]);
```

- [ ] **Step 4: Collapse the dirty-worktree guidance**

Replace `:264-266`:

```php
        error("The [{$name}] worktree has uncommitted changes, so it was not removed.");
        note($status);
        note("Commit or preserve the changes first, or explicitly discard them with:\n\n  php artisan outpost:remove {$name} --discard-changes");
```

with:

```php
        $this->renderError("The [{$name}] worktree has uncommitted changes, so it was not removed.", [
            $status,
            'Commit or preserve the changes first, or discard them explicitly:',
            new BulletedList(["php artisan outpost:remove {$name} --discard-changes"]),
        ]);
```

Replace `:254-255` the same way, folding the trailing `note()` into the `renderError()` remedy.

- [ ] **Step 5: Convert the remaining single calls**

Replace every remaining bare `error($message)` in this file with `$this->renderError($message)`, and both `outro("Removed [...]")` calls at `:136` and `:220` with:

```php
        $this->renderSummary('Instance removed', [], info: $manifest->name);
```

At `:220` the variable is `$name` rather than `$manifest`, so use `info: $name` there. Fold `:218`'s trailing `warning()` into that call's content:

```php
        $this->renderSummary('Instance removed', [
            'If the instance still has a container, delete it manually with [container delete <name>].',
        ], info: $name);
```

Add the trait and `use Laravel\Prompts\Elements\BulletedList;`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/RemoveCommandTest.php`

Expected: PASS. Convert this file's 23 assertions to `expectsOutputToContain()`.

- [ ] **Step 7: Commit**

```bash
git add src/Console/Commands/RemoveCommand.php tests/Feature/Commands/RemoveCommandTest.php
git commit -m "feat: collapse removal guidance into single callouts"
```

---

## Task 8: outpost:upgrade and the rebuild concern

**Files:**
- Modify: `src/Console/Commands/UpgradeCommand.php:79`, `:103`, `:153-175`, `:197-208`, `:233`
- Modify: `src/Console/Concerns/RebuildsInstanceContainers.php:44`, `:109-115`, `:241-242`
- Test: `tests/Feature/Commands/UpgradeCommandTest.php`

**Interfaces:**
- Consumes: `renderError()`, `renderWarning()`, `renderSummary()` from Task 1.
- Produces: no new interfaces.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/UpgradeCommandTest.php`:

```php
it('lists blocking uncommitted files as a bulleted list rather than raw lines', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-billing'));

    fakeUpgradeWithDirtyWorktree();

    $this->artisan('outpost:upgrade', ['name' => 'feature-billing'])
        ->expectsOutputToContain('uncommitted changes')
        ->expectsOutputToContain('Outpost will not discard them')
        ->assertFailed();
});
```

Reuse this file's existing dirty-worktree fake and rename the call to match it.

- [ ] **Step 2: Run the test to verify it passes on payload**

Run: `vendor/bin/pest tests/Feature/Commands/UpgradeCommandTest.php --filter="bulleted list"`

Expected: PASS on payload; it guards the reformat.

- [ ] **Step 3: Convert the raw line loop**

In `src/Console/Concerns/RebuildsInstanceContainers.php`, replace `:109-115`:

```php
            error("The [{$manifest->name}] worktree has uncommitted changes, so its container was not rebuilt.");

            foreach ($files as $file) {
                $this->line($file);
            }

            note('Commit or preserve these files first. Outpost will not discard them.');
```

with:

```php
            $this->renderError("The [{$manifest->name}] worktree has uncommitted changes, so its container was not rebuilt.", [
                new BulletedList($files),
                'Commit or preserve these files first. Outpost will not discard them.',
            ]);
```

Confirm the loop variable name and the collection it iterates by reading `:105-115` first; the plan assumes `$files` is a `list<string>`. If it is named differently, use the existing name.

- [ ] **Step 4: Collapse the rebuild warning pair**

Replace `:241-242`:

```php
            warning("Rebuilding [{$manifest->name}] resets its container-local database and service data.");
            note("The worktree and branch [{$manifest->branch}] are preserved.");
```

with:

```php
            $this->renderWarning("Rebuilding [{$manifest->name}] resets its container-local database and service data.", [
                "The worktree and branch [{$manifest->branch}] are preserved.",
            ]);
```

Leave `:44`, `:101`, `:212`, and `:226` alone: each is a standalone notice with nothing stacked after it.

- [ ] **Step 5: Convert UpgradeCommand's outcome**

Replace `error(...)` calls with `$this->renderError(...)`. Fold `:203`'s trailing note into `:202`'s error:

```php
            $this->renderError($e->getMessage(), [
                'The source worktree and branch were left in place.',
                new BulletedList(['php artisan outpost:logs <name>']),
            ]);
```

Replace the `outro(sprintf(...))` at `:208` with `$this->renderSummary(...)`, keeping the existing sprintf message as the label.

Add the trait to both files plus `use Laravel\Prompts\Elements\BulletedList;`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/UpgradeCommandTest.php`

Expected: PASS. Convert this file's 24 assertions to `expectsOutputToContain()`.

- [ ] **Step 7: Commit**

```bash
git add src/Console/Commands/UpgradeCommand.php src/Console/Concerns/RebuildsInstanceContainers.php tests/Feature/Commands/UpgradeCommandTest.php
git commit -m "feat: render upgrade and rebuild guidance as callouts"
```

---

## Task 9: outpost:list and outpost:info

**Files:**
- Modify: `src/Console/Commands/InfoCommand.php:88-99`
- Modify: `src/Console/Commands/ListCommand.php:47`
- Test: `tests/Feature/Commands/InfoCommandTest.php`, `tests/Feature/Commands/ListCommandTest.php`

**Interfaces:**
- Consumes: `renderSummary()` from Task 1; `InfoCommand::rows()` keeps its existing signature and return type `list<array{string, string}>`.
- Produces: no new interfaces.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/InfoCommandTest.php`:

```php
it('renders instance details as a key value callout', function () {
    app(Outposts::class)->save(fakeManifest(name: 'feature-billing'));

    $this->artisan('outpost:info', ['name' => 'feature-billing'])
        ->expectsOutputToContain('feature-billing')
        ->expectsOutputToContain('feature/billing')
        ->expectsOutputToContain('PHP 8.4')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run the test to verify it passes on payload**

Run: `vendor/bin/pest tests/Feature/Commands/InfoCommandTest.php --filter="key value callout"`

Expected: PASS on payload via `twoColumnDetail`; it guards the reformat.

- [ ] **Step 3: Replace twoColumnDetail**

In `src/Console/Commands/InfoCommand.php`, replace `:88-99`:

```php
            $this->newLine();

            foreach ($this->rows(...) as [$label, $value]) {
                $this->components->twoColumnDetail($label, $value);
            }

            $this->newLine();
```

with:

```php
            $pairs = [];

            foreach ($this->rows(
                $manifest,
                $state,
                $resolvedEndpoints,
                $configuredImage,
                $outdated,
            ) as [$label, $value]) {
                $pairs[$label] = $value;
            }

            $this->renderSummary('Instance details', [
                new KeyValueList($pairs),
            ], info: $manifest->name);
```

Add `use Laravel\Prompts\Elements\KeyValueList;`. `InfoCommand` already uses `ResolvesInstances`, so `RendersOutput` arrives with it.

`rows()` returns a list of pairs rather than a map because endpoint labels can repeat across services; building `$pairs` by assignment means a later duplicate label overwrites an earlier one. Verify against a manifest with two services that no label collides — if one does, disambiguate it in `rows()` rather than changing the structure here.

- [ ] **Step 4: Leave ListCommand's table alone**

`outpost:list` keeps its `table()` call unchanged: it must stay non-blocking and pipeable. Only replace its `info('No instances yet...')` at `:47` with:

```php
            $this->renderSummary('No instances yet.', [
                'Create one with [php artisan outpost].',
            ]);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/InfoCommandTest.php tests/Feature/Commands/ListCommandTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/InfoCommand.php src/Console/Commands/ListCommand.php tests
git commit -m "feat: render instance details as a key value callout"
```

---

## Task 10: The remaining commands and concerns

**Files:**
- Modify: `LogsCommand:38-41`, `ShellCommand:40-43`, `ExecCommand:41-44` and `:57-60`, `OpenCommand:52-58`, `PullCommand:38-48`, `BuildCommand:44-98`, `CertifyCommand:38-52`, `ProcessCommand:72`, `:121`
- Modify: `src/Console/Concerns/FlushesDnsCaches.php:27-28`, `src/Console/Concerns/ResolvesPathRepositoryMounts.php:31,47,68-71,90,99`
- Test: the matching test files

**Interfaces:**
- Consumes: `renderError()`, `renderWarning()`, `renderSummary()` from Task 1.
- Produces: no new interfaces.

Each of these follows one of three mechanical shapes. Apply the matching shape; do not invent new copy.

**Shape A — error plus trailing remedy note becomes one call.** Applies to `ShellCommand:41-42`, `ExecCommand:42-43` and `:58-59`, `PullCommand:45-46`, `FlushesDnsCaches:27-28`:

```php
// before
error("The [{$manifest->name}] instance is not running.");
note("Start it first:\n\n  php artisan outpost:start {$manifest->name}");

// after
$this->renderError("The [{$manifest->name}] instance is not running.", [
    new BulletedList(["php artisan outpost:start {$manifest->name}"]),
]);
```

**Shape B — bare error becomes `$this->renderError($message)`.** Applies to `LogsCommand:39` and `:44`, `ShellCommand:47`, `ExecCommand:64`, `OpenCommand:55`, `BuildCommand:44`, `:56`, `:65`, `:93`, `CertifyCommand:48`.

**Shape C — terminal `outro()` becomes `renderSummary()`.** Applies to:

```php
// OpenCommand:60
$this->renderSummary('Endpoint opened', [new Link($url)], info: $manifest->name);

// PullCommand:51
$this->renderSummary("The [{$image}] image is ready.");

// BuildCommand:98
$this->renderSummary("The [{$image}] image is ready.");

// CertifyCommand:52
$this->renderSummary('Trusted Outpost HTTPS is ready.', [
    'Set OUTPOST_HTTPS=true for new instances.',
]);

// ProcessCommand:121
$this->renderSummary("Restarted [{$selected}].", [], info: $manifest->name);
```

Standalone `info()` calls with nothing stacked after them stay as they are: `PullCommand:41`, `BuildCommand:72`, `CertifyCommand:41`, `ProcessCommand:72`. Standalone `note()` calls likewise: `BuildCommand:77`, `CertifyCommand:44`.

`ResolvesPathRepositoryMounts` is the one place with a genuine warning-then-note pair at `:68-71`. Collapse it:

```php
$this->renderWarning($reused === [] ? $firstMessage : $secondMessage, [
    new BulletedList($newPaths),
]);
```

Read `:66-71` first to recover the exact two message strings; reuse them verbatim rather than rewriting them.

- [ ] **Step 1: Add the trait to each command that lacks it**

Run: `grep -rLn 'RendersOutput\|ResolvesInstances' src/Console/Commands/`

For each file listed, add `use Zacksmash\Outpost\Console\Concerns\RendersOutput;` and the in-class `use RendersOutput;`.

- [ ] **Step 2: Apply shapes A, B, and C**

Work file by file in the order listed above. After each file, run its test:

```bash
vendor/bin/pest tests/Feature/Commands/<Name>CommandTest.php
```

- [ ] **Step 3: Convert the remaining assertions**

Run: `grep -rn 'expectsOutput(' tests/`

Expected: an empty result once every occurrence has moved to `expectsOutputToContain()`.

- [ ] **Step 4: Run the full gate**

Run: `composer test`

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: apply the output vocabulary to the remaining commands"
```

---

## Task 11: Pin the house style

**Files:**
- Modify: `tests/Feature/Commands/CommandExperienceTest.php`

**Interfaces:**
- Consumes: the finished state of Tasks 1–10.
- Produces: no code interfaces.

These assertions read the command sources so they hold for commands added later.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/CommandExperienceTest.php`:

```php
function commandSources(): array
{
    $sources = [];

    foreach (glob(__DIR__.'/../../../src/Console/Commands/*.php') ?: [] as $path) {
        $sources[basename($path)] = (string) file_get_contents($path);
    }

    foreach (glob(__DIR__.'/../../../src/Console/Concerns/*.php') ?: [] as $path) {
        $sources[basename($path)] = (string) file_get_contents($path);
    }

    return $sources;
}

it('never stacks two guidance blocks in a row', function () {
    foreach (commandSources() as $file => $source) {
        expect($source)->not->toMatch(
            '/\b(?:note|warning|error)\([^;]*\);\s*\n\s*(?:note|warning|error)\(/',
            "{$file} stacks two guidance blocks; fold them into one callout.",
        );
    }
});

it('renders instance details through callouts rather than two column details', function () {
    foreach (commandSources() as $file => $source) {
        expect($source)->not->toContain(
            'twoColumnDetail',
            "{$file} still uses twoColumnDetail.",
        );
    }
});

it('never passes a callout type the renderer does not understand', function () {
    foreach (commandSources() as $file => $source) {
        preg_match_all("/callout\([^;]*?,\s*'([a-z]+)'\s*[,)]/s", $source, $matches);

        foreach ($matches[1] as $type) {
            expect($type)->toBeIn(
                ['error', 'warning'],
                "{$file} passes an unsupported callout type [{$type}].",
            );
        }
    }
});

it('does not use the terminal title helper, which renders nothing visible', function () {
    foreach (commandSources() as $file => $source) {
        expect($source)->not->toContain(
            'Laravel\Prompts\title',
            "{$file} uses title(), which only sets the terminal window title.",
        );
    }
});
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/pest tests/Feature/Commands/CommandExperienceTest.php`

Expected: PASS if Tasks 1–10 are complete. Any failure names the exact file still carrying the old idiom — fix that file rather than relaxing the assertion.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Commands/CommandExperienceTest.php
git commit -m "test: pin the console output house style"
```

---

## Task 12: Documentation and the bundled Boost skill

**Files:**
- Modify: `README.md`
- Modify: `resources/boost/skills/**` via the `package-generate-skill` skill

- [ ] **Step 1: Check the README against the new output**

Run: `grep -n 'outpost:doctor\|outpost:info\|outpost:list\|Which instance' README.md`

Expected: a list of passages to verify. Update any that describe the old picker or the unconditional doctor guidance.

- [ ] **Step 2: Document the doctor change**

Add to the README's diagnostics section: troubleshooting guidance appears when a check fails, or on demand with `php artisan outpost:doctor -v`.

- [ ] **Step 3: Regenerate the Boost skill**

Invoke the `package-generate-skill` skill.

- [ ] **Step 4: Run the full gate**

Run: `composer test`

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add README.md resources/boost
git commit -m "docs: describe the reworked command output"
```

---

## Self-Review

**Spec coverage.** Walking the spec's Output Vocabulary section: the `RendersOutput` concern with `canPrompt()`, `renderError()`, and `renderSummary()` is Task 1; the callout-type constraint is a Global Constraint plus a Task 11 assertion; `datatable()` restricted to the picker with an explicit guard is Task 2; `outpost:list` staying a plain `table()` is Task 9 step 4; conditional doctor guidance is Task 3; the `form()` exclusion and the hand-rolled bullet fix in install are Task 4; `KeyValueList` replacing `twoColumnDetail` is Task 9; the never-stack rule is enforced across Tasks 4, 6, 7, 8, 10 and pinned in Task 11; `task()` and `progress()` are available in the vocabulary but no current call site needs them, so no task forces their use; docs and Boost are Task 12. The `--json` byte-for-byte guarantee is a Global Constraint with explicit guards in Tasks 1, 2, and 3.

**Placeholder scan.** No TBD or TODO. Task 10 uses three named shapes with literal before-and-after code and an explicit file-and-line list per shape, rather than "similar to Task N". Four steps direct the executor to read an existing helper or message before reusing it — Task 5 step 1, Task 6 step 1, Task 7 step 1, Task 8 steps 1 and 3, Task 10's `ResolvesPathRepositoryMounts` note. These are deliberate: inventing a second process-faking helper or rewriting an existing message string would be worse than reusing what is there, and the exact existing names are not visible from the greps this plan was built on.

**Type consistency.** `renderError(string, array)`, `renderWarning(string, array)`, `renderSummary(string, array, ?string, string)`, `canPrompt()`, `wantsJsonOutput()`, and `writeJson(array)` are named and ordered identically in Task 1's definition and at every call site in Tasks 2–10. `renderSummary()`'s `info` is always passed as a named argument, so the skipped `$type` parameter never binds positionally. `BulletedList`, `KeyValueList`, `Heading`, and `Link` are the four `Laravel\Prompts\Elements` classes used, and each import is named in the task that first uses it.

**Ordering.** Task 1 must land first: every other task calls its methods. Task 11 must land last: its assertions fail until Tasks 2–10 are complete. Tasks 2–10 are otherwise independent of each other.
