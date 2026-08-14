<?php

declare(strict_types=1);

use Zacksmash\Outpost\Nginx;

beforeEach(function () {
    $this->nginx = new Nginx(app('config'));
});

it('serves the application from /app/public', function () {
    $config = $this->nginx->generate(fakeManifest(php: '8.4'));

    expect($config)->toContain('root /app/public;')
        ->and($config)->toContain('listen 80 default_server;')
        ->and($config)->toContain('try_files $uri $uri/ /index.php?$query_string;');
});

it('pins the fastcgi socket to the instance php version', function () {
    expect($this->nginx->generate(fakeManifest(php: '8.4')))
        ->toContain('fastcgi_pass unix:/run/php/php8.4-fpm.sock;')
        ->and($this->nginx->generate(fakeManifest(php: '8.5')))
        ->toContain('fastcgi_pass unix:/run/php/php8.5-fpm.sock;');
});

it('accepts modern laravel responses with large preload headers', function () {
    $config = $this->nginx->generate(fakeManifest());

    expect($config)->toContain('fastcgi_buffer_size 32k;')
        ->and($config)->toContain('fastcgi_buffers 8 32k;')
        ->and($config)->toContain('fastcgi_busy_buffers_size 64k;');
});

it('denies access to hidden files except well-known', function () {
    expect($this->nginx->generate(fakeManifest(php: '8.4')))
        ->toContain('location ~ /\.(?!well-known).*');
});

it('proxies dynamic requests and websockets to octane', function () {
    $config = $this->nginx->generate(fakeManifest(server: 'octane'));

    expect($config)->toContain('location @octane')
        ->and($config)->toContain('proxy_pass http://127.0.0.1:8000$suffix;')
        ->and($config)->toContain('proxy_set_header Upgrade $http_upgrade;')
        ->and($config)->toContain('proxy_set_header Connection $connection_upgrade;')
        ->and($config)->not->toContain('fastcgi_pass')
        ->and($config)->not->toContain('php-fpm');
});

it('terminates https and redirects plain http requests', function () {
    $config = $this->nginx->generate(fakeManifest(url: 'https://billing-app.outpost'));

    expect($config)->toContain('listen 80 default_server;')
        ->and($config)->toContain('return 301 https://$host$request_uri;')
        ->and($config)->toContain('listen 443 ssl default_server;')
        ->and($config)->toContain('ssl_certificate /outpost-tls/certificate.pem;')
        ->and($config)->toContain('ssl_certificate_key /outpost-tls/key.pem;')
        ->and($config)->toContain('fastcgi_param HTTPS $https if_not_empty;');
});

it('proxies vite hmr through nginx on the public vite port', function () {
    $config = $this->nginx->generate(fakeManifest(frontend: 'vite'));

    expect($config)->toContain('listen 5173;')
        ->and($config)->toContain('proxy_pass http://127.0.0.1:24678;')
        ->and($config)->toContain('proxy_set_header Upgrade $http_upgrade;');
});

it('serves mailpit through nginx using the instance scheme', function () {
    $config = $this->nginx->generate(fakeManifest(
        services: ['mailpit'],
        url: 'https://billing-app.outpost',
    ));

    expect($config)->toContain('listen 8025 ssl;')
        ->and($config)->toContain('proxy_pass http://127.0.0.1:8026;')
        ->and($config)->toContain('ssl_certificate /outpost-tls/certificate.pem;');
});

it('keeps the mailpit web endpoint on loopback when service access is private', function () {
    $config = $this->nginx->generate(fakeManifest(
        services: ['mailpit'],
        exposeServices: false,
    ));

    expect($config)->toContain('listen 127.0.0.1:8025;');
});
