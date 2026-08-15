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

it('describes configured same-origin review links', function () {
    config(['outpost.previews' => [
        'posts' => [
            'path' => '/acme/posts?status=draft#editor',
            'note' => 'Review CRUD behavior',
        ],
        'telescope' => ['path' => '/telescope'],
    ]]);

    $endpoints = $this->endpoints->all(fakeManifest(
        services: [],
        exposeServices: false,
        url: 'https://billing-app.outpost',
    ));

    expect($endpoints)->toBe([
        'application' => ['url' => 'https://billing-app.outpost'],
        'posts' => [
            'url' => 'https://billing-app.outpost/acme/posts?status=draft#editor',
            'path' => '/acme/posts?status=draft#editor',
            'note' => 'Review CRUD behavior',
        ],
        'telescope' => [
            'url' => 'https://billing-app.outpost/telescope',
            'path' => '/telescope',
        ],
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
    config(['outpost.previews' => [
        'posts' => ['path' => '/acme/posts'],
    ]]);

    $manifest = fakeManifest(
        services: ['mailpit'],
        exposeServices: true,
    );

    expect($this->endpoints->browser($manifest, 'app'))->toBe($manifest->url)
        ->and($this->endpoints->browser($manifest, 'mailpit'))->toBe('http://feature-billing-app.outpost:8025')
        ->and($this->endpoints->browser($manifest, 'posts'))->toBe('http://feature-billing-app.outpost/acme/posts');
});

it('rejects unavailable browser endpoints', function () {
    $this->endpoints->browser(fakeManifest(services: []), 'mailpit');
})->throws(RuntimeException::class, 'does not expose a [mailpit] browser endpoint');

it('ignores a preview collection that is not an array when listing endpoints', function () {
    config(['outpost.previews' => '/review']);

    expect($this->endpoints->all(fakeManifest(services: [])))->toBe([
        'application' => ['url' => 'http://feature-billing-app.outpost'],
    ]);
});

it('omits invalid or reserved preview names when listing endpoints', function (string $name) {
    config(['outpost.previews' => [
        $name => ['path' => '/review'],
    ]]);

    $endpoints = $this->endpoints->all(fakeManifest(services: []));

    expect($endpoints['application']['url'])->toBe('http://feature-billing-app.outpost');

    if ($name !== 'application') {
        expect($endpoints)->not->toHaveKey((string) $name);
    }
})->with([
    'uppercase' => ['Posts'],
    'spaces' => ['review posts'],
    'leading dash' => ['-posts'],
    'application' => ['application'],
    'app alias' => ['app'],
    'mysql' => ['mysql'],
    'pgsql' => ['pgsql'],
    'redis' => ['redis'],
    'mailpit' => ['mailpit'],
]);

it('omits malformed preview definitions when listing endpoints', function (mixed $definition) {
    config(['outpost.previews' => ['posts' => $definition]]);

    expect($this->endpoints->all(fakeManifest(services: [])))
        ->toHaveKey('application')
        ->not->toHaveKey('posts');
})->with([
    'string' => ['/review'],
    'empty' => [[]],
    'list' => [['/review']],
    'missing path' => [['note' => 'Review']],
    'non-string path' => [['path' => 42]],
    'relative path' => [['path' => 'review']],
    'network path' => [['path' => '//example.com/review']],
    'absolute url' => [['path' => 'https://example.com/review']],
    'whitespace' => [['path' => '/review posts']],
    'control character' => [['path' => "/review\nposts"]],
    'non-string note' => [['path' => '/review', 'note' => 42]],
    'empty note' => [['path' => '/review', 'note' => '']],
    'multi-line note' => [['path' => '/review', 'note' => "First\nsecond"]],
    'unknown option' => [['path' => '/review', 'description' => 'Review']],
]);

it('reports a malformed preview when it is explicitly requested', function () {
    config(['outpost.previews' => [
        'posts' => ['path' => 'review'],
    ]]);

    $this->endpoints->browser(fakeManifest(), 'posts');
})->throws(RuntimeException::class, 'outpost.previews.posts.path');
