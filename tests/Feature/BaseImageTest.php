<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Zacksmash\Outpost\Runtime;

it('keeps production application servers and watchers out of the sandbox image', function () {
    $dockerfile = File::get(dirname(__DIR__, 2).'/stubs/Dockerfile');

    expect($dockerfile)
        ->not->toContain('php$v-swoole')
        ->not->toContain('frankenphp')
        ->not->toContain('roadrunner')
        ->not->toContain('chokidar');
});

it('prepares a host-mapped non-root application user and collision-free runtime configuration', function () {
    $dockerfile = File::get(dirname(__DIR__, 2).'/stubs/Dockerfile');
    $entrypoint = File::get(dirname(__DIR__, 2).'/stubs/entrypoint.sh');

    expect($dockerfile)
        ->toContain('COMPOSER_ALLOW_SUPERUSER=1')
        ->and($entrypoint)
        ->toContain('OUTPOST_UID')
        ->toContain('OUTPOST_GID')
        ->toContain('useradd')
        ->toContain('/etc/outpost/nginx.conf')
        ->not->toContain('"/outpost/${file}"');
});

it('records the runtime path contract in the image', function () {
    $dockerfile = File::get(dirname(__DIR__, 2).'/stubs/Dockerfile');
    $workflow = File::get(dirname(__DIR__, 2).'/.github/workflows/publish-image.yml');
    $label = Runtime::IMAGE_RUNTIME_PATH_LABEL.'='.Runtime::IMAGE_RUNTIME_PATH;

    expect($dockerfile)->toContain('LABEL '.str_replace('=', '="', $label).'"')
        ->and($workflow)->toContain($label);
});

it('publishes the arm64 image on native hardware with a reusable build cache', function () {
    $workflow = File::get(dirname(__DIR__, 2).'/.github/workflows/publish-image.yml');

    expect($workflow)
        ->toContain('runs-on: ubuntu-24.04-arm')
        ->toContain('platforms: linux/arm64')
        ->toContain('cache-from: type=gha')
        ->toContain('cache-to: type=gha,mode=max')
        ->not->toContain('docker/setup-qemu-action');
});
