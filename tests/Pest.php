<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Runtime;
use Zacksmash\Outpost\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function processPattern(string ...$tokens): string
{
    return implode(' ', array_map(fn (string $token): string => "'{$token}'", $tokens));
}

function fakeImageInspect(
    string $digest = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    array $labels = [Runtime::IMAGE_RUNTIME_PATH_LABEL => Runtime::IMAGE_RUNTIME_PATH],
): string {
    return json_encode([[
        'configuration' => ['descriptor' => ['digest' => $digest]],
        'variants' => [['config' => ['config' => ['Labels' => $labels]]]],
    ]], JSON_THROW_ON_ERROR);
}

function fakeManifest(
    string $name = 'feature-billing',
    string $php = '8.4',
    string $frontend = 'build',
    ?string $database = 'mysql',
    array $services = ['mysql', 'redis'],
    array $processes = [],
    ?string $url = null,
    bool $exposeServices = true,
    ?int $cpus = 4,
    ?string $memory = '2G',
    string $status = 'ready',
    ?string $image = Runtime::PUBLISHED_IMAGE,
    ?string $imageDigest = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $runtime = Runtime::DRIVER,
): Manifest {
    return new Manifest(
        name: $name,
        container: $name.'-app',
        url: $url ?? "http://{$name}-app.outpost",
        branch: 'feature/billing',
        php: $php,
        frontend: $frontend,
        exposeServices: $exposeServices,
        services: $services,
        processes: $processes,
        database: $database,
        createdAt: CarbonImmutable::parse('2026-08-14T09:00:00+00:00'),
        cpus: $cpus,
        memory: $memory,
        status: $status,
        image: $image,
        imageDigest: $imageDigest,
        runtime: $runtime,
    );
}
