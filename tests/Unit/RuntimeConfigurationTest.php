<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Zacksmash\Outpost\RuntimeConfiguration;

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/outpost-runtime-configuration-'.Str::random(10);
    $this->configuration = new RuntimeConfiguration(new Filesystem, $this->home);
});

afterEach(function () {
    File::deleteDirectory($this->home);
});

it('creates the apple container configuration with the outpost domain', function () {
    $this->configuration->setDomain('outpost');

    expect(File::get($this->home.'/.config/container/config.toml'))
        ->toBe("[dns]\ndomain = \"outpost\"\n");
});

it('updates the dns domain without disturbing other runtime configuration', function () {
    $path = $this->home.'/.config/container/config.toml';

    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'TOML'
    [builder]
    cpus = 4

    [dns]
    domain = "test"
    nameservers = ["1.1.1.1"]

    [network]
    mode = "default"
    TOML.PHP_EOL);

    $this->configuration->setDomain('outpost');

    expect(File::get($path))
        ->toContain("[builder]\ncpus = 4")
        ->toContain("[dns]\ndomain = \"outpost\"\nnameservers = [\"1.1.1.1\"]")
        ->toContain("[network]\nmode = \"default\"")
        ->not->toContain('domain = "test"');
});

it('adds a missing domain to an existing dns section', function () {
    $path = $this->home.'/.config/container/config.toml';

    File::ensureDirectoryExists(dirname($path));
    File::put($path, "[dns]\nnameservers = [\"1.1.1.1\"]\n");

    $this->configuration->setDomain('outpost');

    expect(File::get($path))
        ->toBe("[dns]\ndomain = \"outpost\"\nnameservers = [\"1.1.1.1\"]\n");
});

it('appends a dns section when other configuration already exists', function () {
    $path = $this->home.'/.config/container/config.toml';

    File::ensureDirectoryExists(dirname($path));
    File::put($path, "[builder]\ncpus = 4\n");

    $this->configuration->setDomain('outpost');

    expect(File::get($path))
        ->toBe("[builder]\ncpus = 4\n\n[dns]\ndomain = \"outpost\"\n");
});

it('rejects unsafe publication domains', function () {
    $this->configuration->setDomain('../outpost');
})->throws(RuntimeException::class, 'valid publication domain');

it('refuses to write configuration without a safe absolute home directory', function (string $home) {
    (new RuntimeConfiguration(new Filesystem, $home))->setDomain('outpost');
})->with(['', '/', 'relative/home'])->throws(RuntimeException::class, 'home directory');
