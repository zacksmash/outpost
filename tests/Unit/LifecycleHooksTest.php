<?php

declare(strict_types=1);

use Zacksmash\Outpost\LifecycleHooks;

it('resolves named shell-free lifecycle hooks for an instance PHP version', function () {
    config(['outpost.hooks' => [
        'setup' => [
            'search' => ['@php', 'artisan', 'scout:sync-index-settings'],
        ],
        'verify' => [
            'prepare' => ['npm', 'run', 'generate'],
        ],
        'teardown' => [],
    ]]);

    $hooks = app(LifecycleHooks::class);

    expect($hooks->commands(LifecycleHooks::SETUP, '8.4'))->toBe([
        'search' => ['php8.4', 'artisan', 'scout:sync-index-settings'],
    ])->and($hooks->commands(LifecycleHooks::VERIFY, '8.4'))->toBe([
        'prepare' => ['npm', 'run', 'generate'],
    ])->and($hooks->commands(LifecycleHooks::TEARDOWN, '8.4'))->toBe([]);
});

it('allows lifecycle hooks to be omitted', function () {
    config(['outpost.hooks' => []]);

    expect(app(LifecycleHooks::class)->commands(LifecycleHooks::SETUP, '8.4'))->toBe([]);
});

it('allows semicolons as literal arguments for directly executed hooks', function () {
    config(['outpost.hooks.verify' => [
        'php' => ['php', '-r', 'echo "verified";'],
    ]]);

    expect(app(LifecycleHooks::class)->commands(LifecycleHooks::VERIFY, '8.4'))->toBe([
        'php' => ['php', '-r', 'echo "verified";'],
    ]);
});

it('rejects a lifecycle hook collection that is not an array', function () {
    config(['outpost.hooks' => 'php artisan app:setup']);

    app(LifecycleHooks::class)->commands(LifecycleHooks::SETUP, '8.4');
})->throws(RuntimeException::class, 'The [outpost.hooks] value must be an associative array.');

it('rejects unsupported lifecycle points', function () {
    config(['outpost.hooks' => [
        'after_everything' => [],
    ]]);

    app(LifecycleHooks::class)->commands(LifecycleHooks::SETUP, '8.4');
})->throws(RuntimeException::class, 'Unsupported Outpost lifecycle hook [after_everything].');

it('rejects invalid lifecycle hook names', function (mixed $name) {
    config(['outpost.hooks' => [
        'setup' => [$name => ['php', '-v']],
    ]]);

    app(LifecycleHooks::class)->commands(LifecycleHooks::SETUP, '8.4');
})->throws(RuntimeException::class)->with([
    'uppercase' => ['Prepare'],
    'leading number' => ['1prepare'],
    'spaces' => ['prepare app'],
    'numeric key' => [0],
]);

it('rejects malformed lifecycle hook commands', function (mixed $command) {
    config(['outpost.hooks' => [
        'setup' => ['prepare' => $command],
    ]]);

    app(LifecycleHooks::class)->commands(LifecycleHooks::SETUP, '8.4');
})->throws(RuntimeException::class)->with([
    'string' => ['php artisan app:setup'],
    'empty list' => [[]],
    'associative array' => [['command' => 'php']],
    'non-string argument' => [['php', 8]],
    'empty argument' => [['php', '']],
    'newline' => [['php', "artisan\ntest"]],
    'carriage return' => [['php', "artisan\rtest"]],
    'null byte' => [['php', "artisan\0test"]],
]);
