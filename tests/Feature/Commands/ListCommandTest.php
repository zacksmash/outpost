<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-list-'.Str::random(10);

    config(['outpost.path' => $this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('shows a friendly empty state', function () {
    Process::fake();

    $this->artisan('outpost:list')
        ->expectsOutputToContain('No instances yet. Create one with [php artisan outpost].')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('lists every instance with its live state', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));
    app(Outposts::class)->save(fakeManifest('feature-y'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('feature-x')
        ->and($output)->toContain('running')
        ->and($output)->toContain('missing')
        ->and($output)->toContain('http://feature-x-app.outpost')
        ->and($output)->toContain('mysql, redis');
});

it('fails with the real error when the container daemon is unreachable', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('', 'daemon unavailable', 1),
    ]);

    $this->artisan('outpost:list')
        ->expectsOutputToContain('daemon unavailable')
        ->assertFailed();
});
