<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function processPattern(string ...$tokens): string
{
    return implode(' ', array_map(fn (string $token): string => "'{$token}'", $tokens));
}

function fakeManifest(
    string $name = 'feature-billing',
    string $php = '8.4',
    string $server = 'fpm',
    string $frontend = 'build',
    ?string $database = 'mysql',
    array $services = ['mysql', 'redis'],
    array $deferred = ['horizon'],
    array $processes = [],
    ?string $url = null,
    bool $exposeServices = true,
    ?int $cpus = 4,
    ?string $memory = '2G',
): Manifest {
    return new Manifest(
        name: $name,
        container: $name.'-app',
        url: $url ?? "http://{$name}-app.outpost",
        branch: 'feature/billing',
        php: $php,
        server: $server,
        frontend: $frontend,
        exposeServices: $exposeServices,
        services: $services,
        deferred: $deferred,
        processes: $processes,
        database: $database,
        createdAt: CarbonImmutable::parse('2026-08-14T09:00:00+00:00'),
        cpus: $cpus,
        memory: $memory,
    );
}
