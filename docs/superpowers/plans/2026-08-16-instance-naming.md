# Instance Naming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace branch-derived instance names with generated Forge-style adjective-noun names, drop the project-directory suffix from hostnames, and fix the slug defect that turns `feature/some-bug-to-fix` into `featuresome-bug-to-fix`.

**Architecture:** A new `Zacksmash\Outpost\Names` class owns generation, uniqueness, and normalization. It is registered as a container singleton so tests can swap in a deterministic instance. `OutpostCommand` consumes it for the instance-name prompt default and stops appending the project directory to the container name. No manifest migration: existing instances persist `container` and `url` explicitly and keep working.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Laravel Prompts 0.3.22, Pest 4/5, Orchestra Testbench 10/11, PHPStan level 7 via Larastan.

**Spec:** `docs/superpowers/specs/2026-08-16-command-io-design.md`

## Global Constraints

- Namespace is `Zacksmash\Outpost\`; PSR-4 root is `src/`.
- Every PHP file starts with `declare(strict_types=1);`.
- PHPStan level 7 over `src` and `config` must pass: `composer analyse`.
- Pint must pass: `composer lint:check`.
- Type coverage must stay at 100%: `composer test:types`. Every parameter, return, and property needs a type or annotation.
- `--json` output is a machine contract and must be unchanged byte-for-byte by this plan.
- Instance names must satisfy `Str::slug($name) === $name` — `Outposts` enforces this on read.
- Container names must not exceed 63 characters (the DNS label limit).
- Only `src/` and `config/` are analysed; `resources/` is not, but `resources/names/*.php` is `require`d by `src/Names.php` and must be typed via annotation at the require site.

---

## File Structure

**Create:**
- `src/Names.php` — generation, uniqueness, normalization. Sole owner of instance-name derivation.
- `resources/names/adjectives.php` — returns `list<string>`, 128 entries.
- `resources/names/nouns.php` — returns `list<string>`, 128 entries.
- `tests/Unit/NamesTest.php` — unit coverage for all three behaviors.

**Modify:**
- `src/OutpostServiceProvider.php:83` — register the `Names` singleton beside the existing `Outposts` singleton.
- `src/Console/Commands/OutpostCommand.php` — `:202` (container derivation), `:432-446` (`name()`), `:451-469` (`invalidName()`), `:478-489` (`invalidContainer()`).
- `tests/Pest.php` — add a `fakeNames()` helper.
- `tests/Feature/Commands/OutpostCommandTest.php` — container-name expectations and new generated-name coverage.

---

## Task 1: The Names class

**Files:**
- Create: `src/Names.php`
- Create: `resources/names/adjectives.php`
- Create: `resources/names/nouns.php`
- Test: `tests/Unit/NamesTest.php`

**Interfaces:**
- Consumes: `Zacksmash\Outpost\Outposts::exists(string $name): bool` and `Zacksmash\Outpost\Contracts\RuntimeDriver::exists(string $container): bool`, both of which already exist.
- Produces:
  - `Names::__construct(?array $adjectives = null, ?array $nouns = null)`
  - `Names::generate(): string`
  - `Names::unique(Outposts $outposts, RuntimeDriver $runtime): string`
  - `Names::normalize(string $name): string`
  - `Names::ATTEMPTS` — `int`, value `50`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/NamesTest.php`:

```php
<?php

declare(strict_types=1);

use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Names;
use Zacksmash\Outpost\Outposts;

function namesWith(array $adjectives = ['blissful'], array $nouns = ['lake']): Names
{
    return new Names($adjectives, $nouns);
}

function availableStores(array $takenNames = [], array $takenContainers = []): array
{
    $outposts = Mockery::mock(Outposts::class);
    $outposts->shouldReceive('exists')
        ->andReturnUsing(fn (string $name): bool => in_array($name, $takenNames, true));

    $runtime = Mockery::mock(RuntimeDriver::class);
    $runtime->shouldReceive('exists')
        ->andReturnUsing(fn (string $container): bool => in_array($container, $takenContainers, true));

    return [$outposts, $runtime];
}

it('generates a hyphenated adjective and noun pair', function () {
    expect(namesWith()->generate())->toBe('blissful-lake');
});

it('draws from every word in both lists', function () {
    $names = namesWith(['blissful', 'quiet'], ['lake', 'harbor']);

    $seen = [];

    for ($i = 0; $i < 200; $i++) {
        $seen[$names->generate()] = true;
    }

    expect(array_keys($seen))->toHaveCount(4);
});

it('ships wordlists large enough to make collisions rare', function () {
    $adjectives = require __DIR__.'/../../resources/names/adjectives.php';
    $nouns = require __DIR__.'/../../resources/names/nouns.php';

    expect($adjectives)->toHaveCount(128)
        ->and($nouns)->toHaveCount(128)
        ->and(array_unique($adjectives))->toHaveCount(128)
        ->and(array_unique($nouns))->toHaveCount(128);
});

it('only ships wordlist entries that survive the slug round trip', function () {
    $words = [
        ...require __DIR__.'/../../resources/names/adjectives.php',
        ...require __DIR__.'/../../resources/names/nouns.php',
    ];

    foreach ($words as $word) {
        expect(Illuminate\Support\Str::slug($word))->toBe($word);
    }
});

it('returns a generated name when nothing has claimed it', function () {
    [$outposts, $runtime] = availableStores();

    expect(namesWith()->unique($outposts, $runtime))->toBe('blissful-lake');
});

it('regenerates when this application already has a manifest with the name', function () {
    [$outposts, $runtime] = availableStores(takenNames: ['blissful-lake']);

    $names = namesWith(['blissful', 'quiet'], ['lake']);

    expect($names->unique($outposts, $runtime))->toBe('quiet-lake');
});

it('regenerates when another project already has a container with the name', function () {
    [$outposts, $runtime] = availableStores(takenContainers: ['blissful-lake']);

    $names = namesWith(['blissful', 'quiet'], ['lake']);

    expect($names->unique($outposts, $runtime))->toBe('quiet-lake');
});

it('falls back to a numeric suffix when every combination is claimed', function () {
    [$outposts, $runtime] = availableStores(takenNames: ['blissful-lake']);

    expect(namesWith()->unique($outposts, $runtime))->toBe('blissful-lake-2');
});

it('increments the numeric suffix past claimed fallbacks', function () {
    [$outposts, $runtime] = availableStores(
        takenNames: ['blissful-lake', 'blissful-lake-2', 'blissful-lake-3'],
    );

    expect(namesWith()->unique($outposts, $runtime))->toBe('blissful-lake-4');
});

it('hyphenates every non-alphanumeric run when normalizing a name', function (string $input, string $expected) {
    expect(namesWith()->normalize($input))->toBe($expected);
})->with([
    'branch namespace' => ['feature/some-bug-to-fix', 'feature-some-bug-to-fix'],
    'nested namespace' => ['team/feature/billing', 'team-feature-billing'],
    'dotted version' => ['release/v2.1.0', 'release-v2-1-0'],
    'underscores and dots' => ['ZACK_fix.thing', 'zack-fix-thing'],
    'already a slug' => ['blissful-lake', 'blissful-lake'],
    'collapsed separators' => ['feature//__--some', 'feature-some'],
    'surrounding separators' => ['/feature/billing/', 'feature-billing'],
    'accented characters' => ['feature/café', 'feature-cafe'],
    'whitespace' => ['my new outpost', 'my-new-outpost'],
    'empty string' => ['', ''],
    'only separators' => ['///', ''],
]);

it('produces names that survive the slug round trip the manifest enforces', function (string $input) {
    $normalized = namesWith()->normalize($input);

    expect(Illuminate\Support\Str::slug($normalized))->toBe($normalized);
})->with([
    'feature/some-bug-to-fix',
    'release/v2.1.0',
    'ZACK_fix.thing',
    'feature/café',
]);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/NamesTest.php`

Expected: FAIL with `Class "Zacksmash\Outpost\Names" not found`.

- [ ] **Step 3: Create the adjective wordlist**

Create `resources/names/adjectives.php`. Every entry must be lowercase ASCII with no hyphens, so `Str::slug()` returns it unchanged. Exactly 128 unique entries:

```php
<?php

declare(strict_types=1);

return [
    'amber', 'ancient', 'autumn', 'azure', 'billowing', 'bitter', 'blissful', 'bold',
    'boundless', 'brave', 'breezy', 'bright', 'brisk', 'broken', 'calm', 'candid',
    'careful', 'cerulean', 'cheerful', 'clever', 'cloudy', 'cobalt', 'cold', 'cool',
    'copper', 'cosmic', 'crimson', 'crisp', 'curious', 'damp', 'dappled', 'dark',
    'dawn', 'delicate', 'divine', 'downy', 'drifting', 'dry', 'dusky', 'eager',
    'early', 'elated', 'emerald', 'empty', 'endless', 'evening', 'falling', 'fancy',
    'flat', 'floral', 'fragrant', 'frosty', 'gentle', 'gilded', 'glacial', 'golden',
    'graceful', 'green', 'hidden', 'hollow', 'humble', 'icy', 'indigo', 'jolly',
    'keen', 'kindly', 'late', 'lingering', 'little', 'lively', 'lucky', 'lunar',
    'misty', 'morning', 'muddy', 'mute', 'nameless', 'noble', 'northern', 'old',
    'patient', 'placid', 'polished', 'proud', 'purple', 'quiet', 'rapid', 'restless',
    'rough', 'royal', 'rustic', 'sapphire', 'scarlet', 'serene', 'shy', 'silent',
    'silver', 'slender', 'small', 'smooth', 'snowy', 'solemn', 'solitary', 'sparkling',
    'spring', 'steady', 'still', 'stormy', 'summer', 'sunny', 'swift', 'tawny',
    'tender', 'thawing', 'throbbing', 'tidy', 'timber', 'tranquil', 'twilight', 'vast',
    'velvet', 'vermilion', 'wandering', 'weathered', 'white', 'wild', 'winter', 'wispy',
];
```

- [ ] **Step 4: Create the noun wordlist**

Create `resources/names/nouns.php`. Same constraints, exactly 128 unique entries:

```php
<?php

declare(strict_types=1);

return [
    'anchor', 'arbor', 'ash', 'aurora', 'basin', 'bay', 'beacon', 'bird',
    'bloom', 'bluff', 'boulder', 'branch', 'breeze', 'bridge', 'brook', 'butte',
    'canyon', 'cascade', 'cavern', 'cedar', 'chasm', 'cliff', 'cloud', 'clover',
    'coast', 'comet', 'copse', 'coral', 'cove', 'creek', 'crest', 'crown',
    'dawn', 'delta', 'dew', 'dune', 'dusk', 'eddy', 'ember', 'estuary',
    'fern', 'field', 'fjord', 'flame', 'flint', 'foam', 'forest', 'fountain',
    'frost', 'garden', 'gate', 'glade', 'glen', 'grove', 'gulf', 'harbor',
    'haven', 'heath', 'hollow', 'horizon', 'inlet', 'island', 'ivy', 'juniper',
    'lagoon', 'lake', 'lantern', 'leaf', 'ledge', 'lichen', 'lily', 'lodge',
    'meadow', 'mesa', 'mist', 'moon', 'moor', 'moss', 'mountain', 'oasis',
    'ocean', 'orchard', 'palisade', 'pass', 'path', 'peak', 'pebble', 'pine',
    'plain', 'plateau', 'pond', 'prairie', 'quarry', 'rapids', 'ravine', 'reef',
    'ridge', 'rill', 'river', 'sage', 'sanctuary', 'sand', 'sea', 'shade',
    'shore', 'sky', 'slope', 'snowfall', 'spring', 'spruce', 'star', 'stone',
    'stream', 'summit', 'sun', 'thicket', 'tide', 'timber', 'trail', 'tundra',
    'valley', 'vista', 'water', 'waterfall', 'wave', 'willow', 'wind', 'wood',
];
```

- [ ] **Step 5: Write the Names class**

Create `src/Names.php`:

```php
<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\Str;
use RuntimeException;
use Zacksmash\Outpost\Contracts\RuntimeDriver;

class Names
{
    /**
     * The number of candidates tried before falling back to a numeric suffix.
     */
    public const ATTEMPTS = 50;

    /**
     * The adjectives a generated name may start with.
     *
     * @var list<string>
     */
    protected array $adjectives;

    /**
     * The nouns a generated name may end with.
     *
     * @var list<string>
     */
    protected array $nouns;

    /**
     * Create a new name generator.
     *
     * @param  list<string>|null  $adjectives
     * @param  list<string>|null  $nouns
     */
    public function __construct(?array $adjectives = null, ?array $nouns = null)
    {
        /** @var list<string> $defaultAdjectives */
        $defaultAdjectives = require __DIR__.'/../resources/names/adjectives.php';

        /** @var list<string> $defaultNouns */
        $defaultNouns = require __DIR__.'/../resources/names/nouns.php';

        $this->adjectives = $adjectives ?? $defaultAdjectives;
        $this->nouns = $nouns ?? $defaultNouns;
    }

    /**
     * Generate a readable adjective and noun instance name.
     */
    public function generate(): string
    {
        return $this->word($this->adjectives).'-'.$this->word($this->nouns);
    }

    /**
     * Generate a name no manifest and no container on this machine has claimed.
     *
     * Container names are machine-wide rather than scoped to one application,
     * so an unclaimed name must clear both the local manifests and the runtime.
     */
    public function unique(Outposts $outposts, RuntimeDriver $runtime): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $name = $this->generate();

            if (! $this->taken($name, $outposts, $runtime)) {
                return $name;
            }
        }

        $base = $this->generate();

        for ($suffix = 2; $suffix <= self::ATTEMPTS; $suffix++) {
            if (! $this->taken("{$base}-{$suffix}", $outposts, $runtime)) {
                return "{$base}-{$suffix}";
            }
        }

        throw new RuntimeException('Unable to generate an unused instance name. Remove an instance, or choose one with --name.');
    }

    /**
     * Reduce a supplied name to a URL-friendly slug.
     *
     * Str::slug() drops characters like [/] instead of separating on them,
     * which would turn [feature/billing] into [featurebilling], so every run
     * of non-alphanumeric characters becomes a single hyphen first.
     */
    public function normalize(string $name): string
    {
        return Str::slug((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $name));
    }

    /**
     * Determine whether anything on this machine already uses the given name.
     */
    protected function taken(string $name, Outposts $outposts, RuntimeDriver $runtime): bool
    {
        return $outposts->exists($name) || $runtime->exists($name);
    }

    /**
     * Draw one word from the given list.
     *
     * @param  list<string>  $words
     */
    protected function word(array $words): string
    {
        return $words[random_int(0, count($words) - 1)];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/NamesTest.php`

Expected: PASS, all cases green.

- [ ] **Step 7: Run the full gate**

Run: `composer analyse && composer lint:check && composer test:types`

Expected: PHPStan clean, Pint clean, type coverage 100%. If PHPStan reports the `require` results as `mixed`, confirm the `@var list<string>` annotations sit directly above each `require` line as written.

- [ ] **Step 8: Commit**

```bash
git add src/Names.php resources/names tests/Unit/NamesTest.php
git commit -m "feat: add generated instance names and slug normalization"
```

---

## Task 2: Register the singleton and a deterministic test double

**Files:**
- Modify: `src/OutpostServiceProvider.php:83-89`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/ServiceProviderTest.php`

**Interfaces:**
- Consumes: `Names` from Task 1.
- Produces: `Names::class` resolvable as a container singleton, and a `fakeNames(string $name = 'blissful-lake'): void` Pest helper that binds a deterministic instance.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ServiceProviderTest.php`:

```php
it('registers the name generator as a singleton', function () {
    expect(app(Zacksmash\Outpost\Names::class))
        ->toBeInstanceOf(Zacksmash\Outpost\Names::class)
        ->toBe(app(Zacksmash\Outpost\Names::class));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php --filter="name generator"`

Expected: FAIL. Without a binding the container builds a new instance per resolution, so `toBe()` fails on identity.

- [ ] **Step 3: Register the singleton**

In `src/OutpostServiceProvider.php`, add the import `use Zacksmash\Outpost\Names;` if the provider is not already in that namespace, then register beside the existing `Outposts` singleton at `:83`:

```php
$this->app->singleton(Names::class);
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php --filter="name generator"`

Expected: PASS.

- [ ] **Step 5: Add the deterministic test helper**

Append to `tests/Pest.php`. A single-word list makes `generate()` total rather than random, so feature tests can assert on an exact name:

```php
function fakeNames(string $name = 'blissful-lake'): void
{
    [$adjective, $noun] = explode('-', $name, 2);

    app()->instance(
        Zacksmash\Outpost\Names::class,
        new Zacksmash\Outpost\Names([$adjective], [$noun]),
    );
}
```

- [ ] **Step 6: Verify the helper resolves deterministically**

Append to `tests/Feature/ServiceProviderTest.php`:

```php
it('allows tests to pin the generated name', function () {
    fakeNames('quiet-harbor');

    expect(app(Zacksmash\Outpost\Names::class)->generate())->toBe('quiet-harbor');
});
```

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/OutpostServiceProvider.php tests/Pest.php tests/Feature/ServiceProviderTest.php
git commit -m "feat: register the name generator singleton"
```

---

## Task 3: Drop the project-directory suffix from container names

**Files:**
- Modify: `src/Console/Commands/OutpostCommand.php:202` and `:471-489`
- Test: `tests/Feature/Commands/OutpostCommandTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `$container === $name` for newly created instances, so the URL is `{scheme}://{name}.{domain}`. `invalidContainer(string $container): ?string` keeps only the 63-character check.

This task is deliberately separate from Task 4: a reviewer could accept the hostname change while rejecting generated names, or the reverse.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/OutpostCommandTest.php`:

```php
it('uses the instance name alone as the container hostname', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->expectsOutputToContain('http://feature-x.outpost')
        ->assertSuccessful();

    $manifest = app(Outposts::class)->find('feature-x');

    expect($manifest->container)->toBe('feature-x')
        ->and($manifest->url)->toBe('http://feature-x.outpost');
});

it('keeps serving instances created before the hostname changed', function () {
    $legacy = fakeManifest(name: 'feature-billing');

    expect($legacy->container)->toBe('feature-billing-app')
        ->and($legacy->url)->toBe('http://feature-billing-app.outpost');

    app(Outposts::class)->save($legacy);

    expect(app(Outposts::class)->find('feature-billing')?->container)
        ->toBe('feature-billing-app');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Commands/OutpostCommandTest.php --filter="container hostname"`

Expected: FAIL. The container is currently `feature-x-<project-dir-slug>`, so both the output assertion and `expect($manifest->container)` miss.

- [ ] **Step 3: Remove the suffix**

In `src/Console/Commands/OutpostCommand.php`, replace line 202:

```php
$container = $name.'-'.Str::slug(basename($this->laravel->basePath()));
```

with:

```php
$container = $name;
```

- [ ] **Step 4: Simplify the container validation**

Replace `invalidContainer()` at `:471-489` in full. The unslugabble-directory branch is now unreachable, because the project directory no longer feeds the hostname:

```php
    /**
     * Determine why the derived container name is unusable, if it is.
     *
     * The container name becomes the instance's DNS hostname label, so it
     * must stay within the 63-character DNS label limit.
     */
    protected function invalidContainer(string $container): ?string
    {
        if (strlen($container) > 63) {
            return "The [{$container}] container name exceeds the 63-character DNS label limit, so its URL would never resolve. Choose a shorter name with --name.";
        }

        return null;
    }
```

- [ ] **Step 5: Remove the now-unused import if PHPStan flags it**

Run: `composer analyse`

`Str` is still used by `name()` and `invalidName()` at this point in the plan, so the `use Illuminate\Support\Str;` import stays. If PHPStan reports it unused, that means Task 4 landed first — leave the import removal to Task 4's step 6.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/OutpostCommandTest.php`

Expected: PASS. Existing tests that asserted on `feature-x-<dir>` hostnames will fail here — update each to the bare name. Do not update `fakeManifest()` in `tests/Pest.php`: its `container: $name.'-app'` convention now represents a pre-existing instance, which is exactly what the backwards-compatibility test above relies on.

- [ ] **Step 7: Commit**

```bash
git add src/Console/Commands/OutpostCommand.php tests/Feature/Commands/OutpostCommandTest.php
git commit -m "feat: use the instance name alone as the container hostname"
```

---

## Task 4: Generate the default name and normalize supplied ones

**Files:**
- Modify: `src/Console/Commands/OutpostCommand.php:66-81` (handle signature), `:191-194` (call site), `:429-469`
- Test: `tests/Feature/Commands/OutpostCommandTest.php`

**Interfaces:**
- Consumes: `Names::unique()` and `Names::normalize()` from Task 1; `fakeNames()` from Task 2.
- Produces: `name(Outposts $outposts, Names $names, RuntimeDriver $runtime): string` — the branch is no longer a parameter.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Commands/OutpostCommandTest.php`:

```php
it('defaults to a generated name instead of the branch', function () {
    fakeNames('blissful-lake');
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x'])
        ->expectsQuestion('What should the instance be named?', 'blissful-lake')
        ->expectsOutputToContain('http://blissful-lake.outpost')
        ->assertSuccessful();

    expect(app(Outposts::class)->exists('blissful-lake'))->toBeTrue();
});

it('hyphenates a supplied name that a branch namespace would otherwise mangle', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature/some-bug-to-fix'])
        ->expectsOutputToContain('feature-some-bug-to-fix')
        ->expectsOutputToContain('http://feature-some-bug-to-fix.outpost')
        ->assertSuccessful();

    expect(app(Outposts::class)->exists('feature-some-bug-to-fix'))->toBeTrue()
        ->and(app(Outposts::class)->exists('featuresome-bug-to-fix'))->toBeFalse();
});

it('reports when a supplied name was changed to make it URL-friendly', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'Release/v2.1.0'])
        ->expectsOutputToContain('Using [release-v2-1-0]')
        ->assertSuccessful();

    expect(app(Outposts::class)->exists('release-v2-1-0'))->toBeTrue();
});

it('says nothing about normalization when the supplied name is already a slug', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => 'feature-x'])
        ->doesntExpectOutputToContain('Using [feature-x]')
        ->assertSuccessful();
});

it('rejects a supplied name with no URL-friendly characters', function () {
    fakeCreation();

    $this->artisan('outpost', ['branch' => 'feature-x', '--name' => '///'])
        ->expectsOutputToContain('URL-friendly')
        ->assertFailed();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Commands/OutpostCommandTest.php --filter="generated name"`

Expected: FAIL. The prompt default is currently `Str::slug($branch)`, and `--name feature/some-bug-to-fix` is currently rejected by `invalidName()` rather than normalized.

- [ ] **Step 3: Inject Names into handle()**

In `src/Console/Commands/OutpostCommand.php`, add to the `handle()` parameter list (after `Outposts $outposts,` at `:73`):

```php
        Names $names,
```

Add the import beside the other package imports:

```php
use Zacksmash\Outpost\Names;
```

- [ ] **Step 4: Update the call site**

Replace `:191-194`:

```php
            $name = $this->name(
                $outposts,
                $pullRequest === null ? $branch : "pr-{$pullRequest}",
            );
```

with:

```php
            $name = $this->name($outposts, $names, $runtime);
```

- [ ] **Step 5: Rewrite name() and invalidName()**

Replace `name()` at `:429-446` with:

```php
    /**
     * Determine the name of the instance.
     *
     * A supplied name is normalized rather than rejected, so a branch-shaped
     * value like [feature/billing] becomes [feature-billing] instead of
     * failing validation. The prompt default is generated, because the
     * branch is no longer part of the instance hostname.
     */
    protected function name(Outposts $outposts, Names $names, RuntimeDriver $runtime): string
    {
        $supplied = $this->option('name');

        if (is_string($supplied) && $supplied !== '') {
            $name = $names->normalize($supplied);

            if ($name !== $supplied && $name !== '') {
                note("Using [{$name}] for the supplied name [{$supplied}].");
            }

            return $name;
        }

        return text(
            label: 'What should the instance be named?',
            default: $names->unique($outposts, $runtime),
            required: true,
            validate: fn (string $value) => $this->invalidName($outposts, $names, $value),
        );
    }
```

Replace the first guard of `invalidName()` at `:451-455` so validation compares against the normalized form:

```php
    protected function invalidName(Outposts $outposts, Names $names, string $name): ?string
    {
        if ($name === '' || $names->normalize($name) !== $name) {
            return 'The name must be a URL-friendly slug.';
        }
```

Leave the rest of `invalidName()` — the existing-instance check and the TLS-directory collision check — exactly as it is.

- [ ] **Step 6: Update the remaining invalidName() call site**

At `:196`, pass the generator through:

```php
            if (($invalid = $this->invalidName($outposts, $names, $name)) !== null) {
```

Then run `composer analyse`. `Str` is no longer used anywhere in this file, so remove `use Illuminate\Support\Str;`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Commands/OutpostCommandTest.php`

Expected: PASS. Existing tests that relied on the branch-derived default now need either an explicit `--name` or a `fakeNames()` call — most already pass `--name`, so the blast radius is small.

- [ ] **Step 8: Run the full gate**

Run: `composer test`

Expected: PHPStan clean, Pint clean, type coverage 100%, all Pest tests green.

- [ ] **Step 9: Commit**

```bash
git add src/Console/Commands/OutpostCommand.php tests/Feature/Commands/OutpostCommandTest.php
git commit -m "feat: generate instance names and normalize supplied ones"
```

---

## Task 5: Documentation and the bundled Boost skill

**Files:**
- Modify: `README.md`
- Modify: `resources/boost/skills/**` via the `package-generate-skill` skill

**Interfaces:**
- Consumes: the finished behavior from Tasks 1–4.
- Produces: no code interfaces.

- [ ] **Step 1: Find every place the README describes naming or hostnames**

Run: `grep -n 'name\|domain\|\.outpost\|--name' README.md`

Expected: a list of lines to review. Anything showing a branch-derived hostname such as `feature-billing-myapp.outpost` is now wrong.

- [ ] **Step 2: Update the README**

Rewrite the affected passages to describe the current behavior:

- Instance names are generated as adjective-noun pairs; the prompt pre-fills one and you may type your own.
- `--name` accepts branch-shaped values and normalizes them: `--name feature/billing` creates `feature-billing`.
- The instance URL is `https://<name>.<domain>` — no project-directory suffix.
- Instances created before this release keep their existing hostnames.

- [ ] **Step 3: Regenerate the Boost skill**

Invoke the `package-generate-skill` skill, which regenerates `resources/boost/skills` from the implementation and README.

- [ ] **Step 4: Run the full gate**

Run: `composer test`

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add README.md resources/boost
git commit -m "docs: describe generated instance names and hostnames"
```

---

## Self-Review

**Spec coverage.** Walking the spec's Naming and Hostnames section: `generate()`, `unique()`, and `normalize()` are Task 1; the wordlist size and the both-stores uniqueness check are Task 1 tests; the numeric-suffix fallback is Task 1 steps 5 and its two dedicated tests; the generated prompt default and `--name` override are Task 4; normalize-and-report rather than reject is Task 4 steps 1 and 5; the container suffix removal and the deleted `invalidContainer()` branch are Task 3; the retained 63-character check is Task 3 step 4; the no-migration guarantee is Task 3's backwards-compatibility test. Spec step 9 (docs and Boost skill) is Task 5. No gaps.

**Placeholder scan.** No TBD, TODO, or "similar to Task N". Every code step carries the literal code. Both wordlists are written out in full rather than described.

**Type consistency.** `Names::normalize()`, `Names::generate()`, `Names::unique()`, and `Names::ATTEMPTS` are named identically in Tasks 1, 2, and 4. `name()` and `invalidName()` take `(Outposts $outposts, Names $names, ...)` in the same order at both their definitions and all three call sites (`:191`, `:196`, and inside the `validate` closure). `fakeNames()` is defined in Task 2 step 5 before its first use in Task 4 step 1.

**One ordering note for the executor:** Task 3 and Task 4 both touch `OutpostCommand`, and Task 3 step 5 assumes Task 4 has not landed yet. Execute them in order.
