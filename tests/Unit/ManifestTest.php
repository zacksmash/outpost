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

it('loads old octane manifests with the original swoole default', function () {
    $data = fakeManifest(server: 'octane', octaneServer: 'frankenphp')->toArray();

    unset($data['octane_server']);

    expect(Manifest::fromArray($data)->octaneServer)->toBe('swoole');
});

it('loads old manifests with the original web and frontend defaults', function () {
    $data = fakeManifest(server: 'octane', frontend: 'vite')->toArray();

    unset(
        $data['server'],
        $data['octane_server'],
        $data['frontend'],
        $data['expose_services'],
        $data['cpus'],
        $data['memory'],
        $data['status'],
    );

    $manifest = Manifest::fromArray($data);

    expect($manifest->server)->toBe('fpm')
        ->and($manifest->frontend)->toBe('build')
        ->and($manifest->exposeServices)->toBeFalse()
        ->and($manifest->cpus)->toBeNull()
        ->and($manifest->memory)->toBeNull()
        ->and($manifest->status)->toBe('ready');
});

it('can transition provisioning state without changing instance metadata', function () {
    $manifest = fakeManifest(status: 'provisioning');

    expect($manifest->withStatus('failed')->status)->toBe('failed')
        ->and($manifest->withStatus('failed')->name)->toBe($manifest->name);
});

it('rejects invalid lifecycle metadata', function (string $key, mixed $value) {
    Manifest::fromArray([...fakeManifest()->toArray(), $key => $value]);
})->with([
    'status' => ['status', 'broken'],
])->throws(InvalidArgumentException::class);

it('rejects invalid resource metadata', function (string $key, mixed $value) {
    Manifest::fromArray([...fakeManifest()->toArray(), $key => $value]);
})->with([
    'zero cpus' => ['cpus', 0],
    'string cpus' => ['cpus', '4'],
    'empty memory' => ['memory', ''],
    'integer memory' => ['memory', 2048],
])->throws(InvalidArgumentException::class);

it('rejects invalid octane server metadata', function (array $values) {
    Manifest::fromArray([...fakeManifest()->toArray(), ...$values]);
})->with([
    'unsupported server' => [['server' => 'octane', 'octane_server' => 'hyper']],
    'server on fpm instance' => [['server' => 'fpm', 'octane_server' => 'swoole']],
    'non-string server' => [['server' => 'octane', 'octane_server' => 1]],
])->throws(InvalidArgumentException::class, 'manifest [octane_server]');

it('rejects a non-boolean service exposure value', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'expose_services' => 'yes']);
})->throws(InvalidArgumentException::class, 'manifest [expose_services]');

it('rejects processes that are not a list of strings', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'processes' => ['queue', 1]]);
})->throws(InvalidArgumentException::class, 'The manifest [processes] value must only contain strings.');

it('rejects unsupported web and frontend modes', function (string $key, mixed $value) {
    Manifest::fromArray([...fakeManifest()->toArray(), $key => $value]);
})->with([
    'web server' => ['server', 'apache'],
    'null web server' => ['server', null],
    'frontend' => ['frontend', 'webpack'],
    'non-string frontend' => ['frontend', 1],
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
