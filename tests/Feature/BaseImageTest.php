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
        // Ubuntu's stock UID-1000 user would make the default host mapping
        // fail, so the image must remove it.
        ->toContain('userdel --remove ubuntu')
        ->and($entrypoint)
        ->toContain('OUTPOST_UID')
        ->toContain('OUTPOST_GID')
        ->toContain('useradd')
        // The entrypoint must read its generated configuration from the
        // exact runtime path the image contract advertises.
        ->toContain(Runtime::IMAGE_RUNTIME_PATH.'/nginx.conf')
        ->toContain(Runtime::IMAGE_RUNTIME_PATH.'/supervisord.conf');
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
