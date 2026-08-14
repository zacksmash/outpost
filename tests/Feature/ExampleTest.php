<?php

declare(strict_types=1);

use Outpost\Outpost\Outpost;

it('resolves the singleton', function () {
    expect(app(Outpost::class))->toBeInstanceOf(Outpost::class);
});

it('returns the same instance from the container', function () {
    expect(app(Outpost::class))->toBe(app(Outpost::class));
});

it('merges the package config', function () {
    expect(config('outpost.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('outpost::messages.placeholder'))->toBe('Outpost placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('outpost::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('outpost:placeholder')
        ->expectsOutputToContain('Outpost placeholder command executed.')
        ->assertSuccessful();
});
