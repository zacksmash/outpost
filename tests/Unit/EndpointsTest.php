<?php

declare(strict_types=1);

use Zacksmash\Outpost\Endpoints;

beforeEach(function () {
    $this->endpoints = new Endpoints(app('config'));
});

it('describes the application endpoint', function () {
    $endpoints = $this->endpoints->all(fakeManifest(
        services: [],
        url: 'https://billing-app.outpost',
    ));

    expect($endpoints)->toBe([
        'application' => ['url' => 'https://billing-app.outpost'],
    ]);
});

it('describes every exposed service using sandbox credentials', function () {
    $endpoints = $this->endpoints->all(fakeManifest(
        database: 'pgsql',
        services: ['pgsql', 'redis', 'mailpit'],
        exposeServices: true,
        url: 'https://billing-app.outpost',
    ));

    expect($endpoints['pgsql'])->toBe([
        'host' => 'billing-app.outpost',
        'port' => 5432,
        'database' => 'outpost',
        'username' => 'outpost',
        'password' => 'password',
        'url' => 'postgresql://outpost:password@billing-app.outpost:5432/outpost',
    ])->and($endpoints['redis'])->toBe([
        'host' => 'billing-app.outpost',
        'port' => 6379,
        'password' => 'password',
        'url' => 'redis://:password@billing-app.outpost:6379',
    ])->and($endpoints['mailpit'])->toBe([
        'url' => 'https://billing-app.outpost:8025',
        'smtp_host' => 'billing-app.outpost',
        'smtp_port' => 1025,
    ]);
});

it('keeps service endpoints private when exposure is disabled', function () {
    expect($this->endpoints->all(fakeManifest(
        services: ['mysql', 'redis', 'mailpit'],
        exposeServices: false,
    )))->toBe([
        'application' => ['url' => 'http://feature-billing-app.outpost'],
    ]);
});

it('resolves browser endpoints by name', function () {
    $manifest = fakeManifest(
        services: ['mailpit'],
        exposeServices: true,
    );

    expect($this->endpoints->browser($manifest, 'app'))->toBe($manifest->url)
        ->and($this->endpoints->browser($manifest, 'mailpit'))->toBe('http://feature-billing-app.outpost:8025');
});

it('rejects unavailable browser endpoints', function () {
    $this->endpoints->browser(fakeManifest(services: []), 'mailpit');
})->throws(RuntimeException::class, 'does not expose a [mailpit] browser endpoint');
