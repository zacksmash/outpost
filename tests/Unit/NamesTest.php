<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Zacksmash\Outpost\Names;
use Zacksmash\Outpost\Outposts;

function availableStore(array $takenNames = []): Outposts
{
    $outposts = Mockery::mock(Outposts::class);
    $outposts->shouldReceive('exists')
        ->andReturnUsing(fn (string $name): bool => in_array($name, $takenNames, true));

    return $outposts;
}

it('derives a short branch unchanged, with no hash and no truncation', function () {
    expect((new Names)->derive('feature-x', 56))->toBe('feature-x');
});

it('hyphenates a branch namespace when deriving a name', function (string $input, string $expected) {
    expect((new Names)->derive($input, 56))->toBe($expected);
})->with([
    'branch namespace' => ['feature/some-bug-to-fix', 'feature-some-bug-to-fix'],
    'nested namespace' => ['team/feature/billing', 'team-feature-billing'],
    'dotted version' => ['release/v2.1.0', 'release-v2-1-0'],
    'underscores and dots' => ['ZACK_fix.thing', 'zack-fix-thing'],
]);

it('truncates a branch over the budget at a hyphen boundary and appends a hash', function () {
    $branch = 'feature/a-huge-bug-that-needs-a-big-branch-description-which-totally-happens-sometimes';

    expect((new Names)->derive($branch, 56))
        ->toBe('feature-a-huge-bug-that-needs-a-big-branch-'.substr(hash('xxh128', $branch), 0, 4));
});

it('derives the same name for the same long branch every time', function () {
    $branch = 'feature/a-huge-bug-that-needs-a-big-branch-description-which-totally-happens-sometimes';

    $names = new Names;

    expect($names->derive($branch, 56))->toBe($names->derive($branch, 56));
});

it('derives different names for two long branches sharing a prefix', function () {
    $names = new Names;

    $first = $names->derive('feature/a-huge-bug-that-needs-a-big-branch-description-one', 56);
    $second = $names->derive('feature/a-huge-bug-that-needs-a-big-branch-description-two', 56);

    expect($first)->not->toBe($second);
});

it('never derives a name longer than the budget', function (string $branch, int $budget) {
    expect(strlen((new Names)->derive($branch, $budget)))->toBeLessThanOrEqual($budget);
})->with([
    'exactly at the budget' => [str_repeat('a', 56), 56],
    'well over the budget' => ['feature/a-huge-bug-that-needs-a-big-branch-description-which-totally-happens-sometimes', 56],
    'no hyphen to cut back to' => [str_repeat('a', 200), 30],
    'small budget' => ['feature/a-huge-bug-that-needs-a-big-branch-description', 20],
]);

it('returns the candidate unchanged when nothing has claimed it', function () {
    $outposts = availableStore();

    expect((new Names)->unique($outposts, 'feature-billing', 56))->toBe('feature-billing');
});

it('appends a numeric suffix when the candidate already exists', function () {
    $outposts = availableStore(takenNames: ['feature-billing']);

    expect((new Names)->unique($outposts, 'feature-billing', 56))->toBe('feature-billing-2');
});

it('increments the numeric suffix past claimed fallbacks', function () {
    $outposts = availableStore(
        takenNames: ['feature-billing', 'feature-billing-2', 'feature-billing-3'],
    );

    expect((new Names)->unique($outposts, 'feature-billing', 56))->toBe('feature-billing-4');
});

it('throws when every numeric suffix up to the attempt limit is already taken', function () {
    $outposts = availableStore(takenNames: [
        'feature-billing',
        ...array_map(
            fn (int $suffix): string => "feature-billing-{$suffix}",
            range(2, Names::ATTEMPTS),
        ),
    ]);

    (new Names)->unique($outposts, 'feature-billing', 56);
})->throws(
    RuntimeException::class,
    'Unable to find an available instance name after 50 attempts. Remove an instance, or choose one with --name.',
);

it('trims the base name so a numeric suffix on a budget-filling candidate never exceeds the budget', function () {
    $name = str_repeat('a', 56);
    $outposts = availableStore(takenNames: [$name]);

    $unique = (new Names)->unique($outposts, $name, 56);

    expect(strlen($unique))->toBeLessThanOrEqual(56)
        ->and($unique)->toEndWith('-2');
});

it('hyphenates every non-alphanumeric run when normalizing a name', function (string $input, string $expected) {
    expect((new Names)->normalize($input))->toBe($expected);
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
    $normalized = (new Names)->normalize($input);

    expect(Str::slug($normalized))->toBe($normalized);
})->with([
    'feature/some-bug-to-fix',
    'release/v2.1.0',
    'ZACK_fix.thing',
    'feature/café',
]);
