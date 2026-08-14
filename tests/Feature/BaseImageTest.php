<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('installs verified octane server binaries', function () {
    $dockerfile = File::get(dirname(__DIR__, 2).'/stubs/Dockerfile');

    expect($dockerfile)
        ->toContain('ARG FRANKENPHP_VERSION=')
        ->toContain('ARG FRANKENPHP_SHA256=')
        ->toContain('frankenphp-linux-aarch64-gnu')
        ->toContain('${FRANKENPHP_SHA256}  /tmp/frankenphp')
        ->toContain('ARG ROADRUNNER_VERSION=')
        ->toContain('ARG ROADRUNNER_SHA256=')
        ->toContain('roadrunner-${ROADRUNNER_VERSION}-linux-arm64.tar.gz')
        ->toContain('${ROADRUNNER_SHA256}  /tmp/roadrunner.tar.gz');
});
