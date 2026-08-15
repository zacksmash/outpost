<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Zacksmash\Outpost\DependencyCaches;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/outpost-caches-'.Str::random(10);
    $this->caches = new DependencyCaches(
        new Outposts($this->root),
        new Filesystem,
    );
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('creates repository scoped dependency caches and returns their mounts', function () {
    expect($this->caches->mounts())->toBe([
        $this->root.'/.cache/composer:'.DependencyCaches::COMPOSER_TARGET,
        $this->root.'/.cache/npm:'.DependencyCaches::NPM_TARGET,
    ])->and(File::isDirectory($this->root.'/.cache/composer'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/.cache/npm'))->toBeTrue();
});

it('provides package manager environment without sharing installed dependencies', function () {
    expect($this->caches->environment())->toBe([
        'COMPOSER_CACHE_DIR' => DependencyCaches::COMPOSER_TARGET,
        'NPM_CONFIG_CACHE' => DependencyCaches::NPM_TARGET,
    ])->and($this->caches->mounts())->not->toContain(
        $this->root.'/vendor:/app/vendor',
        $this->root.'/node_modules:/app/node_modules',
    );
});

it('fails clearly when a cache path cannot be used as a directory', function () {
    File::ensureDirectoryExists($this->root.'/.cache');
    File::put($this->root.'/.cache/composer', 'not a directory');

    $this->caches->mounts();
})->throws(RuntimeException::class, 'Unable to prepare the Composer dependency cache');
