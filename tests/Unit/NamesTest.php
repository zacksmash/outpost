<?php

declare(strict_types=1);

use Illuminate\Support\Str;
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
        expect(Str::slug($word))->toBe($word);
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

    expect(Str::slug($normalized))->toBe($normalized);
})->with([
    'feature/some-bug-to-fix',
    'release/v2.1.0',
    'ZACK_fix.thing',
    'feature/café',
]);
