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

it('allows a null database', function () {
    $data = [...fakeManifest()->toArray(), 'database' => null];

    expect(Manifest::fromArray($data)->database)->toBeNull();
});

it('loads old manifests without a processes field', function () {
    $data = fakeManifest(processes: ['queue'])->toArray();

    unset($data['processes']);

    expect(Manifest::fromArray($data)->processes)->toBe([]);
});

it('rejects processes that are not a list of strings', function () {
    Manifest::fromArray([...fakeManifest()->toArray(), 'processes' => ['queue', 1]]);
})->throws(InvalidArgumentException::class, 'The manifest [processes] value must only contain strings.');

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
