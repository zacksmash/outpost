<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Zacksmash\Outpost\PathRepositories;
use Zacksmash\Outpost\PathRepositoryScan;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/outpost-repos-'.Str::random(10);
    $this->worktree = $this->root.'/wt';

    File::ensureDirectoryExists($this->worktree);
    File::ensureDirectoryExists($this->root.'/lib');
    File::ensureDirectoryExists($this->root.'/other');
    File::ensureDirectoryExists($this->root.'/home');
    File::put($this->root.'/file.txt', 'not a directory');

    $this->scanner = new PathRepositories($this->root.'/home');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function writeComposer(string $worktree, array $repositories): void
{
    File::put($worktree.'/composer.json', json_encode(
        ['repositories' => $repositories],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ));
}

it('finds nothing without a composer.json file', function () {
    expect($this->scanner->scan($this->worktree)->any())->toBeFalse();
});

it('finds nothing without repositories', function () {
    File::put($this->worktree.'/composer.json', '{"require": {"php": "^8.4"}}');

    expect($this->scanner->scan($this->worktree)->any())->toBeFalse();
});

it('finds path repositories outside the worktree', function () {
    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../lib'],
        ['type' => 'path', 'url' => $this->root.'/other'],
        ['type' => 'composer', 'url' => 'https://example.com'],
    ]);

    $scan = $this->scanner->scan($this->worktree);

    expect($scan->paths)->toBe([$this->root.'/lib', $this->root.'/other'])
        ->and($scan->warnings)->toBe([])
        ->and($scan->mounts())->toBe([
            $this->root.'/lib:/lib:ro',
            $this->root.'/other:'.$this->root.'/other:ro',
        ]);
});

it('resolves relative repositories from the primary project while preserving their app-relative container target', function () {
    $project = $this->root.'/project';
    $worktree = $project.'/.outpost/feature/app';
    $package = $this->root.'/outpost';

    File::ensureDirectoryExists($worktree);
    File::ensureDirectoryExists($package);

    writeComposer($worktree, [
        ['type' => 'path', 'url' => '../outpost'],
    ]);

    $scan = $this->scanner->scan($worktree, $project);

    expect($scan->paths)->toBe([$package])
        ->and($scan->mounts())->toBe([$package.':/outpost:ro']);
});

it('bridges container path repository targets so host tools can follow composer symlinks', function () {
    $project = $this->root.'/project';
    $instance = $project.'/.outpost/feature';
    $worktree = $instance.'/app';
    $package = $this->root.'/outpost';
    $vendor = $worktree.'/vendor/zacksmash';

    File::ensureDirectoryExists($vendor);
    File::ensureDirectoryExists($package);
    writeComposer($worktree, [
        ['type' => 'path', 'url' => '../outpost'],
    ]);
    symlink('../../../outpost', $vendor.'/outpost');

    expect(realpath($vendor.'/outpost'))->toBeFalse();

    $scan = $this->scanner->scan($worktree, $project);
    $scan->createHostBridges($instance);

    expect(realpath($vendor.'/outpost'))->toBe(realpath($package))
        ->and(readlink($instance.'/outpost'))->toBe($package);
});

it('mirrors nested absolute container targets without flattening their path', function () {
    $instance = $this->root.'/instance';

    File::ensureDirectoryExists($instance);
    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => $this->root.'/other'],
    ]);

    $scan = $this->scanner->scan($this->worktree);
    $scan->createHostBridges($instance);

    expect(readlink($instance.$this->root.'/other'))->toBe($this->root.'/other');
});

it('rejects path repository targets that collide with host-side instance metadata', function (string $url) {
    $project = $this->root.'/project';

    File::ensureDirectoryExists($project);
    File::ensureDirectoryExists($this->root.'/runtime');
    File::ensureDirectoryExists($this->root.'/outpost.json');
    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => $url],
    ]);

    $scan = $this->scanner->scan($this->worktree, $project);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings)->toHaveCount(1)
        ->and($scan->warnings[0])->toContain('instance metadata');
})->with([
    'runtime directory' => '../runtime',
    'manifest path' => '../outpost.json',
]);

it('handles repositories keyed by name', function () {
    writeComposer($this->worktree, [
        'shared' => ['type' => 'path', 'url' => '../lib'],
    ]);

    expect($this->scanner->scan($this->worktree)->paths)->toBe([$this->root.'/lib']);
});

it('ignores path repositories inside the worktree', function () {
    File::ensureDirectoryExists($this->worktree.'/packages/internal');

    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => 'packages/internal'],
        ['type' => 'path', 'url' => './packages/internal'],
    ]);

    expect($this->scanner->scan($this->worktree)->any())->toBeFalse();
});

it('does not mistake sibling directories with a shared prefix for inside paths', function () {
    File::ensureDirectoryExists($this->root.'/wt-extra');

    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../wt-extra'],
    ]);

    expect($this->scanner->scan($this->worktree)->paths)->toBe([$this->root.'/wt-extra']);
});

it('deduplicates repeated paths', function () {
    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../lib'],
        ['type' => 'path', 'url' => '../other/../lib'],
    ]);

    expect($this->scanner->scan($this->worktree)->paths)->toBe([$this->root.'/lib']);
});

it('skips glob urls with a warning', function () {
    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../packages/*'],
    ]);

    $scan = $this->scanner->scan($this->worktree);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings)->toHaveCount(1)
        ->and($scan->warnings[0])->toContain('glob');
});

it('warns about paths that are not directories', function () {
    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../missing'],
        ['type' => 'path', 'url' => '../file.txt'],
    ]);

    $scan = $this->scanner->scan($this->worktree);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings)->toHaveCount(2)
        ->and($scan->warnings[0])->toContain('not a directory')
        ->and($scan->warnings[1])->toContain('not a directory');
});

it('refuses paths containing a colon', function () {
    File::ensureDirectoryExists($this->root.'/we:ird');

    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../we:ird'],
    ]);

    $scan = $this->scanner->scan($this->worktree);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings)->toHaveCount(1)
        ->and($scan->warnings[0])->toContain('colon');
});

it('never mounts sensitive locations no matter what', function (string $url) {
    File::ensureDirectoryExists($this->root.'/home/.ssh');
    File::ensureDirectoryExists($this->root.'/home/Dev/lib');

    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => $url],
    ]);

    $scan = (new PathRepositories($this->root.'/home'))->scan($this->worktree);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings)->toHaveCount(1)
        ->and($scan->warnings[0])->toContain('sensitive');
})->with([
    'the home directory' => fn () => $this->root.'/home',
    'a dot directory under home' => fn () => $this->root.'/home/.ssh',
    'an ancestor of home' => fn () => $this->root,
    'the filesystem root' => ['/'],
]);

it('blocks case variants of sensitive locations', function () {
    File::ensureDirectoryExists($this->root.'/home/.ssh');

    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => strtoupper($this->root.'/home').'/.ssh'],
    ]);

    $scan = (new PathRepositories($this->root.'/home'))->scan($this->worktree);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings[0] ?? '')->toContain('sensitive');
});

it('blocks symlinks that resolve into sensitive locations', function () {
    File::ensureDirectoryExists($this->root.'/home/.ssh');
    symlink($this->root.'/home/.ssh', $this->root.'/innocent');

    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../innocent'],
    ]);

    $scan = (new PathRepositories($this->root.'/home'))->scan($this->worktree);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings[0] ?? '')->toContain('sensitive');
});

it('blocks the Library directory under home', function () {
    File::ensureDirectoryExists($this->root.'/home/Library/Keychains');

    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => $this->root.'/home/Library/Keychains'],
    ]);

    $scan = (new PathRepositories($this->root.'/home'))->scan($this->worktree);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings[0] ?? '')->toContain('sensitive');
});

it('mounts nothing at all when home cannot be determined', function () {
    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../lib'],
    ]);

    $scan = (new PathRepositories)->scan($this->worktree);

    expect($scan->any())->toBeFalse()
        ->and($scan->warnings)->toHaveCount(1);
});

it('still mounts ordinary directories under home', function () {
    File::ensureDirectoryExists($this->root.'/home/Dev/lib');

    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => $this->root.'/home/Dev/lib'],
    ]);

    $scan = (new PathRepositories($this->root.'/home'))->scan($this->worktree);

    expect($scan->paths)->toBe([$this->root.'/home/Dev/lib']);
});

it('resolves dotted segments lexically', function () {
    writeComposer($this->worktree, [
        ['type' => 'path', 'url' => '../other/./../lib'],
    ]);

    expect($this->scanner->scan($this->worktree)->paths)->toBe([$this->root.'/lib']);
});

it('exposes an empty scan cleanly', function () {
    $scan = new PathRepositoryScan([], []);

    expect($scan->any())->toBeFalse()
        ->and($scan->mounts())->toBe([]);
});
