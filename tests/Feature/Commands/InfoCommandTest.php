<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\Runtime;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-info-'.Str::random(10);
    config(['outpost.path' => $this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('shows runtime details and service connection information', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        database: 'mysql',
        services: ['mysql', 'redis', 'mailpit'],
        exposeServices: true,
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:info', ['name' => 'billing']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('billing')
        ->and($output)->toContain('apple-container')
        ->and($output)->toContain('running')
        ->and($output)->toContain('PHP 8.4 / PHP-FPM')
        ->and($output)->toContain('4 CPU / 2G')
        ->and($output)->toContain(Runtime::PUBLISHED_IMAGE)
        ->and($output)->toContain('mysql://outpost:password@billing-app.outpost:3306/outpost')
        ->and($output)->toContain('http://billing-app.outpost:8025');
});

it('provides structured json for agents and scripts', function () {
    app(Outposts::class)->save(fakeManifest(name: 'billing', services: [], exposeServices: false));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    $exit = Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($output['name'])->toBe('billing')
        ->and($output['state'])->toBe('missing')
        ->and($output['status'])->toBe('degraded')
        ->and($output['runtime'])->toBe('apple-container')
        ->and($output['image'])->toBe(Runtime::PUBLISHED_IMAGE)
        ->and($output['image_digest'])->toBe('sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($output['configured_image'])->toBe(Runtime::PUBLISHED_IMAGE)
        ->and($output['configured_image_digest'])->toBe('sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($output['outdated'])->toBeFalse()
        ->and($output)->not->toHaveKeys(['server', 'octane_server'])
        ->and($output['process_states'])->toBe([])
        ->and($output['resources'])->toBe(['cpus' => 4, 'memory' => '2G'])
        ->and($output['endpoints']['application']['url'])->toBe('http://billing-app.outpost')
        ->and($output['expose_services'])->toBeFalse();
});

it('includes named review links and their notes in endpoint details', function () {
    config(['outpost.previews' => [
        'posts' => ['path' => '/acme/posts', 'note' => 'Review CRUD behavior'],
    ]]);

    app(Outposts::class)->save(fakeManifest(name: 'billing', services: [], exposeServices: false));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    expect(Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['endpoints']['posts'])->toBe([
        'url' => 'http://billing-app.outpost/acme/posts',
        'path' => '/acme/posts',
        'note' => 'Review CRUD behavior',
    ]);

    $exit = Artisan::call('outpost:info', ['name' => 'billing']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('http://billing-app.outpost/acme/posts')
        ->and($output)->toContain('Review CRUD behavior');
});

it('includes the live state of each configured application process in json', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        services: [],
        processes: ['queue', 'scheduler'],
        exposeServices: false,
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-queue') => Process::result('outpost-queue RUNNING pid 41, uptime 0:02:10'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-scheduler') => Process::result('outpost-scheduler FATAL Exited too quickly', '', 3),
    ]);

    expect(Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['processes'])->toBe(['queue', 'scheduler'])
        ->and($output['process_states'])->toBe([
            'queue' => 'running',
            'scheduler' => 'fatal',
        ]);
});

it('keeps json details available when one process state cannot be read', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        services: [],
        processes: ['queue', 'scheduler'],
        exposeServices: false,
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-queue') => Process::result('outpost-queue RUNNING pid 41, uptime 0:02:10'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'supervisorctl', 'status', 'outpost-scheduler') => Process::result('', 'supervisor unavailable', 1),
    ]);

    expect(Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['endpoints']['application']['url'])->toBe('http://billing-app.outpost')
        ->and($output['process_states'])->toBe([
            'queue' => 'running',
            'scheduler' => 'unknown',
        ]);
});

it('does not query supervisor for human-readable details', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        services: [],
        processes: ['queue'],
        exposeServices: false,
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->artisan('outpost:info', ['name' => 'billing'])->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('omits an invalid preview without hiding the remaining details', function () {
    config(['outpost.previews' => [
        'broken' => ['path' => 'not/absolute'],
    ]]);
    app(Outposts::class)->save(fakeManifest(name: 'billing', services: [], exposeServices: false));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    expect(Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['endpoints'])->toHaveKey('application')
        ->and($output['endpoints'])->not->toHaveKey('broken');
});

it('marks process state unavailable without querying a stopped container', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        services: [],
        processes: ['queue'],
        exposeServices: false,
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    expect(Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['process_states'])->toBe(['queue' => 'unavailable']);

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('marks application processes waiting when provisioning is incomplete', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        services: [],
        processes: ['queue'],
        exposeServices: false,
        status: 'failed',
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    expect(Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['process_states'])->toBe(['queue' => 'waiting']);

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('reports a running instance that failed provisioning as degraded', function () {
    app(Outposts::class)->save(fakeManifest(name: 'billing', status: 'failed'));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $exit = Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output['state'])->toBe('degraded')
        ->and($output['status'])->toBe('failed');
});

it('reports an instance created from another image as outdated', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        image: 'ghcr.io/zacksmash/outpost:0.1.1',
        imageDigest: 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ));

    Process::fake([
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    Artisan::call('outpost:info', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['outdated'])->toBeTrue()
        ->and($output['image'])->toBe('ghcr.io/zacksmash/outpost:0.1.1')
        ->and($output['configured_image'])->toBe(Runtime::PUBLISHED_IMAGE);
});
