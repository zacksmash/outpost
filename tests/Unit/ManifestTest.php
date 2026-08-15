<?php

declare(strict_types=1);

use Zacksmash\Outpost\Manifest;

it('round trips through its array representation', function () {
    $manifest = fakeManifest();

    $restored = Manifest::fromArray($manifest->toArray());

    expect($restored->toArray())->toBe($manifest->toArray());
});

it('serializes created_at as an ISO-8601 string', function () {
    expect(fakeManifest()->toArray()['created_at'])->toBe('2026-08-14T09:00:00+00:00');
});

it('records the runtime driver and defaults legacy manifests to apple container', function () {
    $data = fakeManifest()->toArray();

    expect($data['runtime'])->toBe('apple-container');

    unset($data['runtime']);

    expect(Manifest::fromArray($data)->runtime)->toBe('apple-container');
});

it('round trips a future runtime driver identifier without changing it', function () {
    $manifest = fakeManifest(runtime: 'future-runtime');

    expect(Manifest::fromArray($manifest->toArray())->runtime)->toBe('future-runtime')
        ->and($manifest->withStatus('failed')->runtime)->toBe('future-runtime')
        ->and($manifest->withImage('outpost:next', 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->runtime)
        ->toBe('future-runtime');
});

it('reports the services it uses', function () {
    $manifest = fakeManifest();

    expect($manifest->uses('mysql'))->toBeTrue()
        ->and($manifest->uses('redis'))->toBeTrue()
        ->and($manifest->uses('pgsql'))->toBeFalse();
});

it('reports whether the instance uses https', function () {
    expect(fakeManifest()->secure())->toBeFalse()
        ->and(fakeManifest(url: 'https://feature-billing-app.outpost')->secure())->toBeTrue();
});

it('allows a null database', function () {
    $data = [...fakeManifest()->toArray(), 'database' => null];

    expect(Manifest::fromArray($data)->database)->toBeNull();
});

it('loads old manifests without a processes field', function () {
    $data = fakeManifest(processes: ['queue'])->toArray();

    unset($data['processes']);

    expect(Manifest::fromArray($data)->processes)->toBe([]);
});

it('normalizes legacy octane and vite manifests to the standard runtime', function () {
    $data = [
        ...fakeManifest()->toArray(),
        'server' => 'octane',
        'octane_server' => 'frankenphp',
        'frontend' => 'vite',
        'deferred' => ['horizon'],
    ];

    $manifest = Manifest::fromArray($data);

    expect($manifest->frontend)->toBe('build')
        ->and($manifest->toArray())->not->toHaveKeys(['server', 'octane_server', 'deferred']);
});

it('loads old manifests with the original frontend and lifecycle defaults', function () {
    $data = fakeManifest()->toArray();

    unset(
        $data['frontend'],
        $data['expose_services'],
        $data['cpus'],
        $data['memory'],
        $data['status'],
        $data['image'],
        $data['image_digest'],
    );

    $manifest = Manifest::fromArray($data);

    expect($manifest->frontend)->toBe('build')
        ->and($manifest->exposeServices)->toBeFalse()
        ->and($manifest->cpus)->toBeNull()
        ->and($manifest->memory)->toBeNull()
        ->and($manifest->status)->toBe('ready')
        ->and($manifest->image)->toBeNull()
        ->and($manifest->imageDigest)->toBeNull();
});

it('detects image reference and digest upgrades while keeping legacy state unknown', function () {
    $current = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $new = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    expect(fakeManifest()->imageOutdated('ghcr.io/zacksmash/outpost:0.2.1', $current))->toBeFalse()
        ->and(fakeManifest()->imageOutdated('ghcr.io/zacksmash/outpost:0.2.2', $current))->toBeTrue()
        ->and(fakeManifest()->imageOutdated('ghcr.io/zacksmash/outpost:0.2.1', $new))->toBeTrue()
        ->and(fakeManifest()->imageOutdated('ghcr.io/zacksmash/outpost:0.2.1', null))->toBeNull()
        ->and(fakeManifest(image: null, imageDigest: null)
            ->imageOutdated('ghcr.io/zacksmash/outpost:0.2.1', $current))->toBeNull();
});

it('updates image identity without changing instance metadata', function () {
    $digest = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    $manifest = fakeManifest(status: 'provisioning')->withImage('outpost:next', $digest);

    expect($manifest->image)->toBe('outpost:next')
        ->and($manifest->imageDigest)->toBe($digest)
        ->and($manifest->name)->toBe('feature-billing')
        ->and($manifest->status)->toBe('provisioning');
});

it('can transition provisioning state without changing instance metadata', function () {
    $manifest = fakeManifest(status: 'provisioning');

    expect($manifest->withStatus('failed')->status)->toBe('failed')
        ->and($manifest->withStatus('failed')->name)->toBe($manifest->name);
});

it('updates configured processes without changing instance metadata', function () {
    $manifest = fakeManifest(processes: ['old'])->withProcesses(['queue']);

    expect($manifest->processes)->toBe(['queue'])
        ->and($manifest->name)->toBe('feature-billing');
});

it('rejects invalid lifecycle metadata', function (string $key, mixed $value) {
    Manifest::fromArray([...fakeManifest()->toArray(), $key => $value]);
})->with([
    'status' => ['status', 'broken'],
])->throws(InvalidArgumentException::class);

it('rejects an invalid runtime driver identifier', function (mixed $value) {
    Manifest::fromArray([...fakeManifest()->toArray(), 'runtime' => $value]);
})->with([
    'empty runtime' => [''],
    'uppercase runtime' => ['Apple'],
    'non-string runtime' => [1],
])->throws(InvalidArgumentException::class, 'manifest [runtime]');

it('rejects invalid resource metadata', function (string $key, mixed $value) {
    Manifest::fromArray([...fakeManifest()->toArray(), $key => $value]);
})->with([
    'zero cpus' => ['cpus', 0],
    'string cpus' => ['cpus', '4'],
    'empty memory' => ['memory', ''],
    'integer memory' => ['memory', 2048],
])->throws(InvalidArgumentException::class);

it('rejects invalid image metadata', function (array $values) {
    Manifest::fromArray([...fakeManifest()->toArray(), ...$values]);
})->with([
    'empty image' => [['image' => '']],
    'missing digest' => [['image_digest' => null]],
    'digest without image' => [['image' => null]],
    'malformed digest' => [['image_digest' => 'latest']],
])->throws(InvalidArgumentException::class);

it('rejects a non-boolean service exposure value', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'expose_services' => 'yes']);
})->throws(InvalidArgumentException::class, 'manifest [expose_services]');

it('rejects processes that are not a list of strings', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'processes' => ['queue', 1]]);
})->throws(InvalidArgumentException::class, 'The manifest [processes] value must only contain strings.');

it('rejects unsupported frontend modes', function (mixed $value) {
    Manifest::fromArray([...fakeManifest()->toArray(), 'frontend' => $value]);
})->with([
    'frontend' => ['webpack'],
    'non-string frontend' => [1],
])->throws(InvalidArgumentException::class);

it('rejects a missing name', function () {
    $data = fakeManifest()->toArray();

    unset($data['name']);

    Manifest::fromArray($data);
})->throws(InvalidArgumentException::class, 'The manifest [name] value must be a string.');

it('rejects a non-string php version', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'php' => 8.4]);
})->throws(InvalidArgumentException::class, 'The manifest [php] value must be a string.');

it('rejects services that are not a list', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'services' => ['db' => 'mysql']]);
})->throws(InvalidArgumentException::class, 'The manifest [services] value must be a list.');

it('rejects services containing non-strings', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'services' => ['mysql', 1]]);
})->throws(InvalidArgumentException::class, 'The manifest [services] value must only contain strings.');

it('rejects a non-string database', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'database' => ['mysql']]);
})->throws(InvalidArgumentException::class, 'The manifest [database] value must be a string or null.');

it('rejects an invalid created_at date', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'created_at' => 'not-a-date']);
})->throws(InvalidArgumentException::class, 'The manifest [created_at] value is not a valid date.');

it('rejects an empty created_at date', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'created_at' => '']);
})->throws(InvalidArgumentException::class, 'The manifest [created_at] value is not a valid date.');
