<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Nginx;

function nginxManifest(string $php): Manifest
{
    return new Manifest(
        name: 'feature-x',
        container: 'feature-x-app',
        url: 'http://feature-x-app.outpost',
        branch: 'feature/x',
        php: $php,
        services: [],
        deferred: [],
        database: 'sqlite',
        createdAt: CarbonImmutable::parse('2026-08-14T09:00:00+00:00'),
    );
}

it('serves the application from /app/public', function () {
    $config = (new Nginx)->generate(nginxManifest('8.4'));

    expect($config)->toContain('root /app/public;')
        ->and($config)->toContain('listen 80 default_server;')
        ->and($config)->toContain('try_files $uri $uri/ /index.php?$query_string;');
});

it('pins the fastcgi socket to the instance php version', function () {
    expect((new Nginx)->generate(nginxManifest('8.4')))
        ->toContain('fastcgi_pass unix:/run/php/php8.4-fpm.sock;')
        ->and((new Nginx)->generate(nginxManifest('8.5')))
        ->toContain('fastcgi_pass unix:/run/php/php8.5-fpm.sock;');
});

it('denies access to hidden files except well-known', function () {
    expect((new Nginx)->generate(nginxManifest('8.4')))
        ->toContain('location ~ /\.(?!well-known).*');
});
