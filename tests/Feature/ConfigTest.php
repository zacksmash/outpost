<?php

declare(strict_types=1);

it('exposes sensible defaults', function () {
    expect(config('outpost.domain'))->toBe('outpost')
        ->and(config('outpost.image'))->toBe('outpost-base')
        ->and(config('outpost.dns'))->toBe('1.1.1.1')
        ->and(config('outpost.path'))->toBe('.outpost')
        ->and(config('outpost.php'))->toBe(['8.4', '8.5'])
        ->and(config('outpost.timeout'))->toBe(60);
});

it('detects services by default', function () {
    expect(config('outpost.services'))->toBeNull();
});

it('ships development-only database credentials', function () {
    expect(config('outpost.database'))->toBe([
        'database' => 'outpost',
        'username' => 'outpost',
        'password' => 'password',
    ]);
});
