<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/outpost-tests-'.Str::random(10);
    $this->outposts = new Outposts($this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('builds instance paths', function () {
    expect($this->outposts->path('feature-x'))->toBe($this->root.'/feature-x')
        ->and($this->outposts->worktreePath('feature-x'))->toBe($this->root.'/feature-x/app')
        ->and($this->outposts->runtimePath('feature-x'))->toBe($this->root.'/feature-x/runtime')
        ->and($this->outposts->manifestPath('feature-x'))->toBe($this->root.'/feature-x/outpost.json');
});

it('rejects names that are not URL-friendly slugs', function (string $name) {
    expect(fn () => $this->outposts->path($name))
        ->toThrow(InvalidArgumentException::class, 'must be a URL-friendly slug');
})->with([
    'empty string' => [''],
    'uppercase' => ['Feature-X'],
    'slash' => ['feature/x'],
    'traversal' => ['../secrets'],
    'spaces' => ['two words'],
]);

it('guards every path builder', function () {
    expect(fn () => $this->outposts->worktreePath(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->outposts->runtimePath(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->outposts->manifestPath(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->outposts->delete(''))->toThrow(InvalidArgumentException::class);
});

it('saves and finds a manifest', function () {
    $manifest = fakeManifest();

    $this->outposts->save($manifest);

    expect($this->outposts->find('feature-billing')?->toArray())->toBe($manifest->toArray());
});

it('writes human-editable JSON', function () {
    $this->outposts->save(fakeManifest());

    $json = File::get($this->root.'/feature-billing/outpost.json');

    expect($json)->toContain('    "name": "feature-billing"')
        ->and($json)->toContain('"url": "http://feature-billing-app.outpost"')
        ->and($json)->toEndWith(PHP_EOL);
});

it('returns null for an unknown instance', function () {
    expect($this->outposts->find('missing'))->toBeNull();
});

it('refuses a manifest claiming a different name', function () {
    File::ensureDirectoryExists($this->root.'/feature-x');
    File::put(
        $this->root.'/feature-x/outpost.json',
        json_encode(fakeManifest('feature-y')->toArray(), JSON_THROW_ON_ERROR),
    );

    $this->outposts->find('feature-x');
})->throws(RuntimeException::class, 'does not belong to the [feature-x] instance');

it('refuses a manifest naming an unexpected container', function () {
    $data = [...fakeManifest('feature-x')->toArray(), 'container' => 'someone-elses-box'];

    File::ensureDirectoryExists($this->root.'/feature-x');
    File::put($this->root.'/feature-x/outpost.json', json_encode($data, JSON_THROW_ON_ERROR));

    $this->outposts->find('feature-x');
})->throws(RuntimeException::class, 'names an unexpected container');

it('throws a clear error for a corrupt manifest', function () {
    File::ensureDirectoryExists($this->root.'/broken');
    File::put($this->root.'/broken/outpost.json', '{not json');

    $this->outposts->find('broken');
})->throws(RuntimeException::class, 'contains invalid JSON');

it('knows which instances exist', function () {
    $this->outposts->save(fakeManifest());

    expect($this->outposts->exists('feature-billing'))->toBeTrue()
        ->and($this->outposts->exists('missing'))->toBeFalse();
});

it('lists every instance and skips strays', function () {
    $this->outposts->save(fakeManifest('feature-a'));
    $this->outposts->save(fakeManifest('feature-b'));
    File::ensureDirectoryExists($this->root.'/no-manifest-here');
    File::ensureDirectoryExists($this->root.'/My Notes');
    File::ensureDirectoryExists($this->root.'/Not-A-Slug');
    File::ensureDirectoryExists($this->root.'/broken-one');
    File::put($this->root.'/broken-one/outpost.json', '{not json');

    expect(collect($this->outposts->all())->map->name->all())
        ->toBe(['feature-a', 'feature-b']);
});

it('returns no instances before anything is created', function () {
    expect($this->outposts->all())->toBe([]);
});

it('writes runtime configuration files', function () {
    $this->outposts->writeRuntime('feature-x', [
        'nginx.conf' => 'server {}',
        'supervisord.conf' => '[program:nginx]',
    ]);

    expect(File::get($this->root.'/feature-x/runtime/nginx.conf'))->toBe('server {}')
        ->and(File::get($this->root.'/feature-x/runtime/supervisord.conf'))->toBe('[program:nginx]');
});

it('rejects invalid names when writing runtime configuration', function () {
    $this->outposts->writeRuntime('../evil', []);
})->throws(InvalidArgumentException::class);

it('deletes an instance directory', function () {
    $this->outposts->save(fakeManifest());

    $this->outposts->delete('feature-billing');

    expect(File::isDirectory($this->root.'/feature-billing'))->toBeFalse();
});

it('quietly ignores deleting an unknown instance', function () {
    $this->outposts->delete('missing');

    expect(true)->toBeTrue();
});
