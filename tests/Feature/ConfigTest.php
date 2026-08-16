<?php

declare(strict_types=1);

use Zacksmash\Outpost\Runtime;

it('exposes sensible defaults', function () {
    expect(config('outpost.domain'))->toBe('outpost')
        ->and(config('outpost.image'))->toBe(Runtime::PUBLISHED_IMAGE)
        ->and(Runtime::PUBLISHED_IMAGE)->toBe('ghcr.io/zacksmash/outpost:0.5.3')
        ->and(config('outpost.dns'))->toBe('1.1.1.1')
        ->and(config('outpost.path'))->toBe('.outpost')
        ->and(config('outpost.resources'))->toBe(['cpus' => 4, 'memory' => '2G'])
        ->and(config('outpost.php'))->toBe(['8.4', '8.5'])
        ->and(config('outpost.frontend'))->toBe('build')
        ->and(config('outpost.https'))->toBeFalse()
        ->and(config('outpost.tls'))->toBe(['path' => '.outpost/tls'])
        ->and(config('outpost.expose_services'))->toBeTrue()
        ->and(config('outpost.previews'))->toBe([])
        ->and(config('outpost.processes'))->toBe([])
        ->and(config('outpost.checks'))->toBe([])
        ->and(config('outpost.hooks'))->toBe([
            'setup' => [],
            'verify' => [],
            'teardown' => [],
        ])
        ->and(config('outpost.lifecycle_timeout'))->toBe(30)
        ->and(config('outpost.timeout'))->toBe(60);
});

it('casts instance resource values from the environment', function () {
    putenv('OUTPOST_CPUS=6');
    putenv('OUTPOST_MEMORY=3G');

    try {
        $config = require dirname(__DIR__, 2).'/config/outpost.php';

        expect($config['resources'])->toBe(['cpus' => 6, 'memory' => '3G']);
    } finally {
        putenv('OUTPOST_CPUS');
        putenv('OUTPOST_MEMORY');
    }
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

it('casts the lifecycle timeout to an integer even from the environment', function () {
    putenv('OUTPOST_LIFECYCLE_TIMEOUT=45');

    try {
        $config = require dirname(__DIR__, 2).'/config/outpost.php';

        expect($config['lifecycle_timeout'])->toBe(45);
    } finally {
        putenv('OUTPOST_LIFECYCLE_TIMEOUT');
    }
});

it('ships development-only database credentials', function () {
    expect(config('outpost.database'))->toBe([
        'database' => 'outpost',
        'username' => 'outpost',
        'password' => 'password',
    ]);
});
