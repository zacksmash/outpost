<?php

declare(strict_types=1);

use Zacksmash\Outpost\Nginx;

it('serves the application from /app/public', function () {
    $config = (new Nginx)->generate(fakeManifest(php: '8.4'));

    expect($config)->toContain('root /app/public;')
        ->and($config)->toContain('listen 80 default_server;')
        ->and($config)->toContain('try_files $uri $uri/ /index.php?$query_string;');
});

it('pins the fastcgi socket to the instance php version', function () {
    expect((new Nginx)->generate(fakeManifest(php: '8.4')))
        ->toContain('fastcgi_pass unix:/run/php/php8.4-fpm.sock;')
        ->and((new Nginx)->generate(fakeManifest(php: '8.5')))
        ->toContain('fastcgi_pass unix:/run/php/php8.5-fpm.sock;');
});

it('accepts modern laravel responses with large preload headers', function () {
    $config = (new Nginx)->generate(fakeManifest());

    expect($config)->toContain('fastcgi_buffer_size 32k;')
        ->and($config)->toContain('fastcgi_buffers 8 32k;')
        ->and($config)->toContain('fastcgi_busy_buffers_size 64k;');
});

it('denies access to hidden files except well-known', function () {
    expect((new Nginx)->generate(fakeManifest(php: '8.4')))
        ->toContain('location ~ /\.(?!well-known).*');
});
