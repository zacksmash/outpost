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

    $this->root = sys_get_temp_dir().'/outpost-verify-'.Str::random(10);
    $this->worktree = $this->root.'/billing/app';

    config([
        'outpost.path' => $this->root,
        'outpost.checks' => [],
        'outpost.hooks' => [
            'setup' => [],
            'verify' => [],
            'teardown' => [],
        ],
    ]);

    File::ensureDirectoryExists($this->worktree);
    File::put($this->worktree.'/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));
    app(Outposts::class)->save(fakeManifest(name: 'billing'));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('runs repository-owned verify hooks as report checks', function () {
    config(['outpost.hooks.verify' => [
        'generate' => ['npm', 'run', 'generate'],
    ]]);

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'npm', 'run', 'generate') => Process::result('generated'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'npm', 'run', 'build') => Process::result('built'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'curl').' *' => Process::result(''),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(0)
        ->and($checks['Hook: verify/generate']['status'])->toBe('PASS')
        ->and($checks['Hook: verify/generate']['command'])->toBe(['npm', 'run', 'generate']);
});

it('reports verify hook failures and inspects Git after hooks finish', function () {
    config(['outpost.hooks.verify' => [
        'generate' => ['npm', 'run', 'generate'],
    ]]);
    $hookRan = false;

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'npm', 'run', 'generate') => function () use (&$hookRan) {
            $hookRan = true;

            return Process::result('', 'generation failed', 1);
        },
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'npm', 'run', 'build') => Process::result('built'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'curl').' *' => Process::result(''),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => function () use (&$hookRan) {
            expect($hookRan)->toBeTrue();

            return Process::result("?? generated.php\n");
        },
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(1)
        ->and($output['git']['changes'])->toBe(['?? generated.php'])
        ->and($checks['Hook: verify/generate']['status'])->toBe('FAIL')
        ->and($checks['Hook: verify/generate']['detail'])->toContain('generation failed')
        ->and($checks['Git worktree']['status'])->toBe('WARN');
});

it('verifies a running instance and emits a complete agent-readable report', function () {
    config([
        'outpost.checks' => [
            'tests' => ['@php', 'artisan', 'test', '--parallel'],
        ],
    ]);

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(" M app/Invoice.php\n?? notes.txt\n"),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'npm', 'run', 'build') => Process::result('built'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'php8.4', 'artisan', 'test', '--parallel') => Process::result('passed'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'curl').' *' => Process::result(''),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(0)
        ->and($output['verified'])->toBeTrue()
        ->and($output['name'])->toBe('billing')
        ->and($output['branch'])->toBe('feature/billing')
        ->and($output['url'])->toBe('http://billing-app.outpost')
        ->and($output['state'])->toBe('running')
        ->and($output['status'])->toBe('ready')
        ->and($output['runtime'])->toBe('apple-container')
        ->and($output['active_runtime'])->toBe('apple-container')
        ->and($output['image'])->toBe(Runtime::PUBLISHED_IMAGE)
        ->and($output['image_digest'])->toBe('sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($output['configured_image'])->toBe(Runtime::PUBLISHED_IMAGE)
        ->and($output['configured_image_digest'])->toBe('sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($output['outdated'])->toBeFalse()
        ->and($output['git'])->toBe([
            'head' => '0123456789abcdef0123456789abcdef01234567',
            'dirty' => true,
            'changes' => [' M app/Invoice.php', '?? notes.txt'],
        ])
        ->and($checks['Runtime']['status'])->toBe('PASS')
        ->and($checks['Container']['status'])->toBe('PASS')
        ->and($checks['Image']['status'])->toBe('PASS')
        ->and($checks['Git worktree']['status'])->toBe('WARN')
        ->and($checks['Production assets']['status'])->toBe('PASS')
        ->and($checks['Check: tests']['status'])->toBe('PASS')
        ->and($checks['Application']['status'])->toBe('PASS');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'billing-app', 'php8.4', 'artisan', 'test', '--parallel',
    ]);
});

it('fails truthfully without running in-container checks when the container is missing', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(''),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(1)
        ->and($output['verified'])->toBeFalse()
        ->and($output['state'])->toBe('missing')
        ->and($output['status'])->toBe('degraded')
        ->and($checks['Container']['status'])->toBe('FAIL')
        ->and($checks['Production assets']['status'])->toBe('SKIP')
        ->and($checks['Configured checks']['status'])->toBe('SKIP')
        ->and($checks['Application']['status'])->toBe('SKIP');

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('reports build and configured-check failures without hiding their output', function () {
    config([
        'outpost.checks' => [
            'types' => ['composer', 'types:check'],
        ],
    ]);

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'npm', 'run', 'build') => Process::result('', 'Vite build failed', 1),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'composer', 'types:check') => Process::result('PHPStan found an error', '', 1),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'curl').' *' => Process::result(''),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(1)
        ->and($output['verified'])->toBeFalse()
        ->and($checks['Production assets']['status'])->toBe('FAIL')
        ->and($checks['Production assets']['detail'])->toContain('Vite build failed')
        ->and($checks['Check: types']['status'])->toBe('FAIL')
        ->and($checks['Check: types']['detail'])->toContain('PHPStan found an error')
        ->and($checks['Application']['status'])->toBe('PASS');
});

it('renders a concise human report and skips checks that do not apply', function () {
    app(Outposts::class)->save(fakeManifest(name: 'billing', frontend: 'none'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'curl').' *' => Process::result(''),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Production assets')
        ->and($output)->toContain('Configured checks')
        ->and($output)->toContain('Verified [billing]: http://billing-app.outpost');
});

it('skips production assets when the application has no build script', function () {
    File::delete($this->worktree.'/package.json');

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'curl').' *' => Process::result(''),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(0)
        ->and($checks['Production assets']['status'])->toBe('SKIP')
        ->and($checks['Production assets']['detail'])->toContain('no build script');

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('npm', $process->command, true));
});

it('fails on image and application mismatches', function () {
    app(Outposts::class)->save(fakeManifest(
        name: 'billing',
        frontend: 'none',
        image: 'ghcr.io/zacksmash/outpost:0.1.1',
        imageDigest: 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(''),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'curl').' *' => Process::result('', 'connection refused', 7),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(1)
        ->and($output['verified'])->toBeFalse()
        ->and($output['outdated'])->toBeTrue()
        ->and($checks['Runtime']['status'])->toBe('PASS')
        ->and($checks['Image']['status'])->toBe('FAIL')
        ->and($checks['Application']['status'])->toBe('FAIL');
});

it('does not execute a container owned by a different runtime driver', function () {
    app(Outposts::class)->save(fakeManifest(name: 'billing', runtime: 'future-runtime'));

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(''),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(1)
        ->and($checks['Runtime']['status'])->toBe('FAIL')
        ->and($checks['Lifecycle hooks']['status'])->toBe('SKIP')
        ->and($checks['Application']['status'])->toBe('SKIP');

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('fails when the host worktree is missing', function () {
    File::deleteDirectory($this->worktree);

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'npm', 'run', 'build') => Process::result('built'),
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'curl').' *' => Process::result(''),
    ]);

    $exit = Artisan::call('outpost:verify', ['name' => 'billing', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = collect($output['checks'])->keyBy('name');

    expect($exit)->toBe(1)
        ->and($output['verified'])->toBeFalse()
        ->and($output['git'])->toBe(['head' => null, 'dirty' => null, 'changes' => []])
        ->and($checks['Production assets']['status'])->toBe('SKIP')
        ->and($checks['Git worktree']['status'])->toBe('FAIL')
        ->and($checks['Git worktree']['detail'])->toContain('is missing');

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});

it('rejects malformed configured checks before executing them', function () {
    config([
        'outpost.checks' => [
            'tests' => 'php artisan test',
        ],
    ]);

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'billing-app', 'status' => ['state' => 'running']],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', Runtime::PUBLISHED_IMAGE) => Process::result(fakeImageInspect()),
        processPattern('git', '-C', $this->worktree, 'rev-parse', 'HEAD') => Process::result("0123456789abcdef0123456789abcdef01234567\n"),
        processPattern('git', '-C', $this->worktree, 'status', '--short') => Process::result(''),
    ]);

    $this->artisan('outpost:verify', ['name' => 'billing'])
        ->expectsOutputToContain('outpost.checks.tests')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'exec');
});
