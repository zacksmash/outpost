<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Runtime;

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

it('emits an empty json collection without consulting the runtime', function () {
    Process::fake();

    $exit = Artisan::call('outpost:list', ['--json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([]);

    Process::assertNothingRan();
});

it('lists every instance with its live state', function () {
    app(Outposts::class)->save(fakeManifest('feature-x', processes: ['queue']));
    app(Outposts::class)->save(fakeManifest('feature-y'));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Name')
        ->and($output)->toContain('Branch')
        ->and($output)->toContain('State')
        ->and($output)->toContain('URL')
        ->and($output)->toContain('feature-x')
        ->and($output)->toContain('feature/billing')
        ->and($output)->toContain('running')
        ->and($output)->toContain('missing')
        ->and($output)->toContain('http://feature-x-app.outpost')
        ->and($output)->not->toContain('Runtime')
        ->and($output)->not->toContain('Services')
        ->and($output)->not->toContain('App Processes')
        ->and($output)->not->toContain('Upgrade')
        ->and($output)->not->toContain('PHP 8.4 / FPM')
        ->and($output)->not->toContain('mysql, redis')
        ->and($output)->not->toContain('queue');
});

it('summarizes a mixed set of instance states in the callout', function () {
    app(Outposts::class)->save(fakeManifest('feature-a'));
    app(Outposts::class)->save(fakeManifest('feature-b'));
    app(Outposts::class)->save(fakeManifest('feature-c'));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-a-app', 'status' => ['state' => 'running']],
            ['id' => 'feature-b-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('3 instances')
        ->and($output)->toContain('1 running')
        ->and($output)->toContain('1 stopped')
        ->and($output)->toContain('1 missing');
});

it('flags outdated images with an actionable upgrade line', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));
    app(Outposts::class)->save(fakeManifest(
        'feature-y',
        image: 'ghcr.io/zacksmash/outpost:0.1.1',
        imageDigest: 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('1 outdated — run php artisan outpost:upgrade')
        ->and($output)->not->toContain('All images current');
});

it('reports all images current when nothing is outdated', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));
    app(Outposts::class)->save(fakeManifest('feature-y'));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('All images current')
        ->and($output)->not->toContain('outdated — run php artisan outpost:upgrade');
});

it('uses singular wording for exactly one instance', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('1 instance')
        ->and($output)->not->toContain('1 instances');
});

it('emits machine-readable instance state', function () {
    app(Outposts::class)->save(fakeManifest('feature-x', processes: ['queue']));
    app(Outposts::class)->save(fakeManifest(
        'feature-y',
        image: 'ghcr.io/zacksmash/outpost:0.1.1',
        imageDigest: 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list', ['--json' => true]);
    $instances = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($instances)->toHaveCount(2)
        ->and($instances[0])->toMatchArray([
            'name' => 'feature-x',
            'container' => 'feature-x-app',
            'runtime' => 'apple-container',
            'url' => 'http://feature-x-app.outpost',
            'php' => '8.4',
            'frontend' => 'build',
            'services' => ['mysql', 'redis'],
            'processes' => ['queue'],
            'state' => 'running',
            'image' => Runtime::PUBLISHED_IMAGE,
            'configured_image' => Runtime::PUBLISHED_IMAGE,
            'outdated' => false,
        ])
        ->and($instances[1]['state'])->toBe('missing')
        ->and($instances[1]['status'])->toBe('degraded')
        ->and($instances[1]['outdated'])->toBeTrue();
});

it('keeps every dropped column available in json even though the table no longer shows it', function () {
    app(Outposts::class)->save(fakeManifest('feature-x', processes: ['queue']));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list', ['--json' => true]);
    $instances = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    // Runtime, Image, and Upgrade were dropped from the human-readable table,
    // but the fields they were derived from are a machine contract and must
    // still be present, byte-for-byte, in the json output.
    expect($exit)->toBe(0)
        ->and($instances)->toHaveCount(1)
        ->and($instances[0])->toMatchArray([
            'name' => 'feature-x',
            'runtime' => 'apple-container',
            'php' => '8.4',
            'services' => ['mysql', 'redis'],
            'processes' => ['queue'],
            'image' => Runtime::PUBLISHED_IMAGE,
            'image_digest' => 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'configured_image' => Runtime::PUBLISHED_IMAGE,
            'outdated' => false,
        ]);
});

it('reports a running instance that failed provisioning as degraded', function () {
    app(Outposts::class)->save(fakeManifest('failed', status: 'failed'));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'failed-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:list', ['--json' => true]);
    $instances = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($instances[0]['state'])->toBe('degraded')
        ->and($instances[0]['status'])->toBe('failed');
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

it('returns runtime failures as json when requested', function () {
    app(Outposts::class)->save(fakeManifest('feature-x'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('', 'daemon unavailable', 1),
    ]);

    $exit = Artisan::call('outpost:list', ['--json' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'Unable to list the existing containers: daemon unavailable',
        ]);
});
