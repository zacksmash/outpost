<?php

declare(strict_types=1);

use Zacksmash\Outpost\VerificationChecks;

it('resolves configured checks and pins the php placeholder', function () {
    config(['outpost.checks' => [
        'tests' => ['@php', 'artisan', 'test', '--filter=high priority'],
        'lint' => ['composer', 'lint'],
    ]]);

    expect(app(VerificationChecks::class)->commands('8.4'))->toBe([
        'tests' => ['php8.4', 'artisan', 'test', '--filter=high priority'],
        'lint' => ['composer', 'lint'],
    ]);
});

it('allows no configured checks', function () {
    config(['outpost.checks' => []]);

    expect(app(VerificationChecks::class)->commands('8.4'))->toBe([]);
});

it('allows semicolons as literal arguments for directly executed checks', function () {
    config(['outpost.checks' => [
        'php' => ['php', '-r', 'echo "checked";'],
    ]]);

    expect(app(VerificationChecks::class)->commands('8.4'))->toBe([
        'php' => ['php', '-r', 'echo "checked";'],
    ]);
});

it('rejects a check collection that is not an array', function () {
    config(['outpost.checks' => 'php artisan test']);

    app(VerificationChecks::class)->commands('8.4');
})->throws(RuntimeException::class, 'The [outpost.checks] value must be an associative array.');

it('rejects unsafe check names', function (string $name) {
    config(['outpost.checks' => [
        $name => ['@php', 'artisan', 'test'],
    ]]);

    app(VerificationChecks::class)->commands('8.4');
})->with([
    'spaces' => ['test suite'],
    'section syntax' => ['tests]'],
    'leading dash' => ['-tests'],
    'uppercase' => ['Tests'],
])->throws(RuntimeException::class);

it('rejects malformed check commands', function (mixed $command) {
    config(['outpost.checks' => ['tests' => $command]]);

    app(VerificationChecks::class)->commands('8.4');
})->with([
    'string command' => ['php artisan test'],
    'empty command' => [[]],
    'associative command' => [['binary' => 'php']],
    'non-string token' => [['@php', 'artisan', 42]],
    'empty token' => [['@php', '']],
    'newline token' => [['@php', "artisan\nmalicious"]],
    'carriage-return token' => [['@php', "artisan\rmalicious"]],
    'null-byte token' => [['@php', "artisan\0malicious"]],
])->throws(RuntimeException::class);
