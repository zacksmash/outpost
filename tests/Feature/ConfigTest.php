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

it('casts the boot timeout to an integer even from the environment', function () {
    putenv('OUTPOST_TIMEOUT=90');

    try {
        $config = require dirname(__DIR__, 2).'/config/outpost.php';

        expect($config['timeout'])->toBe(90);
    } finally {
        putenv('OUTPOST_TIMEOUT');
    }
});

it('ships development-only database credentials', function () {
    expect(config('outpost.database'))->toBe([
        'database' => 'outpost',
        'username' => 'outpost',
        'password' => 'password',
    ]);
});
