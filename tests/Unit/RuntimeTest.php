<?php

declare(strict_types=1);

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Zacksmash\Outpost\Runtime;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->runtime = new Runtime;
});

it('reads the container cli version', function () {
    Process::fake([
        processPattern('container', '--version') => Process::result('container CLI version 1.2.2 (build: release)'),
    ]);

    expect($this->runtime->version())->toBe('1.2.2');
});

it('rejects an unrecognized container cli version', function () {
    Process::fake([
        processPattern('container', '--version') => Process::result('container development build'),
    ]);

    $this->runtime->version();
})->throws(RuntimeException::class, 'Unable to determine the Apple container CLI version');

it('reads the container system status', function () {
    Process::fake([
        processPattern('container', 'system', 'status', '--format', 'json') => Process::result('{"status":"running"}'),
    ]);

    expect($this->runtime->systemStatus())->toBe('running');
});

it('starts the container system', function () {
    Process::fake();

    $this->runtime->startSystem();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'system', 'start',
    ] && $process->timeout === 600);
});

it('stops the container system for configuration changes', function () {
    Process::fake();

    $this->runtime->stopSystem();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'system', 'stop',
    ] && $process->timeout === 30);
});

it('registers a local dns domain with administrator privileges', function () {
    Process::fake();

    $this->runtime->registerDomain('outpost');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'sudo', 'container', 'system', 'dns', 'create', 'outpost',
    ]);
});

it('rejects a malformed container system status', function () {
    Process::fake([
        processPattern('container', 'system', 'status', '--format', 'json') => Process::result('not-json'),
    ]);

    $this->runtime->systemStatus();
})->throws(RuntimeException::class, 'Unable to parse the container system status');

it('recognizes a registered dns domain', function () {
    Process::fake([
        processPattern('container', 'system', 'dns', 'list') => Process::result("DOMAIN\nbox\noutpost\n"),
    ]);

    expect($this->runtime->domainRegistered('outpost'))->toBeTrue()
        ->and($this->runtime->domainRegistered('out'))->toBeFalse()
        ->and($this->runtime->domainRegistered('test'))->toBeFalse();
});

it('throws when the dns domains cannot be listed', function () {
    Process::fake([
        processPattern('container', 'system', 'dns', 'list') => Process::result('', 'daemon unavailable', 1),
    ]);

    $this->runtime->domainRegistered('outpost');
})->throws(RuntimeException::class, 'daemon unavailable');

it('checks whether an image exists', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result('', 'not found', 1),
        processPattern('container', 'image', 'inspect', 'other') => Process::result('[{"reference":"other"}]'),
    ]);

    expect($this->runtime->hasImage('outpost-base'))->toBeFalse()
        ->and($this->runtime->hasImage('other'))->toBeTrue();
});

it('reads labels from apple container image metadata', function () {
    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result(json_encode([
            [
                'variants' => [[
                    'config' => [
                        'config' => [
                            'Labels' => [
                                Runtime::IMAGE_RUNTIME_PATH_LABEL => '/etc/outpost',
                                'org.opencontainers.image.version' => '0.1.0',
                            ],
                        ],
                    ],
                ]],
            ],
        ], JSON_THROW_ON_ERROR)),
        processPattern('container', 'image', 'inspect', 'missing') => Process::result('', 'not found', 1),
    ]);

    expect($this->runtime->imageMetadata('outpost-base'))->toBe([
        'labels' => [
            Runtime::IMAGE_RUNTIME_PATH_LABEL => '/etc/outpost',
            'org.opencontainers.image.version' => '0.1.0',
        ],
    ])->and($this->runtime->imageMetadata('missing'))->toBeNull();
});

it('pulls an image from an oci registry', function () {
    Process::fake();

    $this->runtime->pull('ghcr.io/zacksmash/outpost:0.1.2');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.1.2',
    ]);
});

it('surfaces the real error when an image pull fails', function () {
    Process::fake([
        processPattern('container', 'image', 'pull').' *' => Process::result('', 'denied', 1),
    ]);

    $this->runtime->pull('ghcr.io/zacksmash/outpost:0.1.2');
})->throws(RuntimeException::class, 'Unable to pull the [ghcr.io/zacksmash/outpost:0.1.2] image: denied');

it('builds an image with dns, tag, and build arguments', function () {
    Process::fake();

    $this->runtime->build('outpost-base', '1.1.1.1', '/pkg/stubs', ['DB_DATABASE' => 'outpost']);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'build', '--dns', '1.1.1.1', '--tag', 'outpost-base',
        '--build-arg', 'DB_DATABASE=outpost',
        '/pkg/stubs',
    ]);
});

it('surfaces the real error when a build fails', function () {
    Process::fake([
        processPattern('container', 'build').' *' => Process::result('', 'ppa unreachable', 1),
    ]);

    $this->runtime->build('outpost-base', '1.1.1.1', '/pkg/stubs');
})->throws(RuntimeException::class, 'Unable to build the [outpost-base] image: ppa unreachable');

it('boots a detached container with volumes and dns', function () {
    Process::fake();

    $this->runtime->boot('feature-x-app', 'outpost-base', '1.1.1.1', [
        '/host/app:/app',
        '/host/runtime:/etc/outpost:ro',
    ], uid: 501, gid: 20);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'run', '--detach', '--name', 'feature-x-app', '--dns', '1.1.1.1',
        '--cpus', '4', '--memory', '2G',
        '--env', 'OUTPOST_UID=501', '--env', 'OUTPOST_GID=20',
        '--volume', '/host/app:/app',
        '--volume', '/host/runtime:/etc/outpost:ro',
        'outpost-base',
    ] && $process->timeout === 600);
});

it('boots a container with configured resource limits', function () {
    Process::fake();

    $this->runtime->boot('feature-x-app', 'outpost-base', '1.1.1.1', [], cpus: 6, memory: '3072MiB');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'run', '--detach', '--name', 'feature-x-app', '--dns', '1.1.1.1',
        '--cpus', '6', '--memory', '3072MiB',
        'outpost-base',
    ]);
});

it('rejects invalid container resource limits', function (int $cpus, string $memory, string $message) {
    Process::fake();

    expect(fn () => $this->runtime->boot(
        'feature-x-app', 'outpost-base', '1.1.1.1', [], $cpus, $memory,
    ))->toThrow(RuntimeException::class, $message);

    Process::assertNothingRan();
})->with([
    'zero cpus' => [0, '2G', 'outpost.resources.cpus'],
    'fractional memory' => [4, '1.5G', 'outpost.resources.memory'],
    'memory without a size' => [4, 'large', 'outpost.resources.memory'],
]);

it('surfaces the real error when a boot fails', function () {
    Process::fake([
        processPattern('container', 'run').' *' => Process::result('', 'no such image', 125),
    ]);

    $this->runtime->boot('feature-x-app', 'outpost-base', '1.1.1.1', []);
})->throws(RuntimeException::class, 'Unable to boot the container [feature-x-app]: no such image');

it('starts, stops, and deletes containers', function (string $method, string $verb) {
    config(['outpost.lifecycle_timeout' => 17]);

    Process::fake();

    $this->runtime->{$method}('feature-x-app');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', $verb, 'feature-x-app',
    ] && $process->timeout === 17);
})->with([
    'start' => ['start', 'start'],
    'stop' => ['stop', 'stop'],
    'delete' => ['delete', 'delete'],
]);

it('releases application processes after provisioning', function () {
    Process::fake();

    $this->runtime->releaseProcesses('billing-app');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/root', '--user', 'root', '--workdir', '/app',
        'billing-app', 'touch', '/var/lib/outpost/ready',
    ]);
});

it('bounds container inventory calls with the lifecycle timeout', function () {
    config(['outpost.lifecycle_timeout' => 17]);

    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('[]'),
    ]);

    expect($this->runtime->states())->toBe([]);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'list', '--all', '--format', 'json',
    ] && $process->timeout === 17);
});

it('rejects an invalid lifecycle timeout before invoking the runtime', function (mixed $timeout) {
    config(['outpost.lifecycle_timeout' => $timeout]);

    Process::fake();

    expect(fn () => $this->runtime->stop('feature-x-app'))
        ->toThrow(RuntimeException::class, 'outpost.lifecycle_timeout');

    Process::assertNothingRan();
})->with([
    'zero' => 0,
    'negative' => -1,
    'string' => '30',
]);

it('turns a lifecycle timeout into actionable runtime guidance', function () {
    config(['outpost.lifecycle_timeout' => 17]);

    $process = new SymfonyProcess(['container', 'stop', 'feature-x-app']);
    $process->setTimeout(17);

    $timeout = new ProcessTimedOutException(
        new SymfonyProcessTimedOutException($process, SymfonyProcessTimedOutException::TYPE_GENERAL),
        Process::result(),
    );

    Process::fake(fn () => $timeout);

    expect(fn () => $this->runtime->stop('feature-x-app'))
        ->toThrow(RuntimeException::class, 'timed out after 17 seconds. The Apple container VM may be unresponsive.');
});

it('surfaces the real error when application processes cannot be released', function () {
    Process::fake([
        processPattern('container', 'exec').' *'.processPattern('billing-app', 'touch', '/var/lib/outpost/ready') => Process::result('', 'container stopped', 1),
    ]);

    $this->runtime->releaseProcesses('billing-app');
})->throws(RuntimeException::class, 'Unable to release the application processes in [billing-app]: container stopped');

it('surfaces the real error when a lifecycle command fails', function (string $method) {
    Process::fake([
        "'container'*" => Process::result('', 'went sideways', 1),
    ]);

    expect(fn () => $this->runtime->{$method}('feature-x-app'))
        ->toThrow(RuntimeException::class, 'went sideways');
})->with(['start', 'stop', 'delete']);

it('executes commands inside a container and returns the raw result', function () {
    Process::fake([
        processPattern('container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app', 'feature-x-app', 'php', 'artisan', 'migrate', '--force') => Process::result('migrated', 'warning', 2),
    ]);

    $result = $this->runtime->exec('feature-x-app', ['php', 'artisan', 'migrate', '--force']);

    expect($result->exitCode())->toBe(2)
        ->and(trim($result->output()))->toBe('migrated')
        ->and(trim($result->errorOutput()))->toBe('warning');
});

it('can execute explicitly as root', function () {
    Process::fake();

    $this->runtime->run('feature-x-app', ['apt-get', 'update'], root: true);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/root', '--user', 'root', '--workdir', '/app',
        'feature-x-app', 'apt-get', 'update',
    ]);
});

it('streams a non-interactive command and passes its exit code through', function () {
    Process::fake([
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'php', 'artisan', 'test', '--filter=Feature') => Process::result('', '', 3),
    ]);

    expect($this->runtime->run('feature-x-app', [
        'php', 'artisan', 'test', '--filter=Feature',
    ]))->toBe(3);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'php', 'artisan', 'test', '--filter=Feature',
    ]);
});

it('reads container state from the json listing', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
            ['id' => 'feature-y-app', 'status' => ['state' => 'stopped']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    expect($this->runtime->state('feature-x-app'))->toBe('running')
        ->and($this->runtime->state('feature-y-app'))->toBe('stopped')
        ->and($this->runtime->state('missing'))->toBeNull()
        ->and($this->runtime->running('feature-x-app'))->toBeTrue()
        ->and($this->runtime->running('feature-y-app'))->toBeFalse()
        ->and($this->runtime->exists('feature-y-app'))->toBeTrue()
        ->and($this->runtime->exists('missing'))->toBeFalse();
});

it('maps every container to its state', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result(json_encode([
            ['id' => 'feature-x-app', 'status' => ['state' => 'running']],
            ['id' => 'feature-y-app', 'status' => ['state' => 'stopped']],
            ['id' => 'broken', 'status' => []],
        ], JSON_THROW_ON_ERROR)),
    ]);

    expect($this->runtime->states())->toBe([
        'feature-x-app' => 'running',
        'feature-y-app' => 'stopped',
    ]);
});

it('degrades only a stale ready manifest whose container is missing', function () {
    expect($this->runtime->instanceStatus(fakeManifest(status: 'ready'), 'missing'))->toBe('degraded')
        ->and($this->runtime->instanceStatus(fakeManifest(status: 'ready'), 'stopped'))->toBe('ready')
        ->and($this->runtime->instanceStatus(fakeManifest(status: 'failed'), 'missing'))->toBe('failed')
        ->and($this->runtime->instanceStatus(fakeManifest(status: 'provisioning'), 'missing'))->toBe('provisioning');
});

it('opens a shell and passes the exit code through', function () {
    $tty = SymfonyProcess::isTtySupported();

    Process::fake([
        processPattern('container', 'exec', '-i').' *' => Process::result('', '', 3),
    ]);

    expect($this->runtime->shell('feature-x-app'))->toBe(3);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '-i', ...($tty ? ['-t'] : []),
        '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app',
        'feature-x-app', 'bash',
    ]);
});

it('force deletes a container that would not stop', function () {
    Process::fake();

    $this->runtime->delete('feature-x-app', force: true);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'delete', '--force', 'feature-x-app',
    ]);
});

it('does not treat an interrupted follow as a failure', function () {
    Process::fake([
        processPattern('container', 'logs', '--follow', 'feature-x-app') => Process::result('', 'interrupted', 130),
    ]);

    $this->runtime->logs('feature-x-app', follow: true);

    expect(true)->toBeTrue();
});

it('fetches logs and throws with the real error on failure', function () {
    Process::fake([
        processPattern('container', 'logs', 'feature-x-app') => Process::result('', 'no such container', 1),
    ]);

    $this->runtime->logs('feature-x-app');
})->throws(RuntimeException::class, 'no such container');

it('throws when the container list is not valid json', function () {
    Process::fake([
        processPattern('container', 'list', '--all', '--format', 'json') => Process::result('not json'),
    ]);

    $this->runtime->state('feature-x-app');
})->throws(RuntimeException::class, 'Unable to parse the container list output as JSON.');

it('waits for a container to answer http', function () {
    Sleep::fake();

    Process::fake([
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'curl').' *' => Process::sequence()
            ->push(Process::result('', 'refused', 7))
            ->push(Process::result('', 'refused', 7))
            ->push(Process::result('')),
    ]);

    expect($this->runtime->awaitReady('feature-x-app', 30))->toBeTrue();

    Sleep::assertSleptTimes(2);
});

it('checks an https instance through its tls listener', function () {
    Process::fake();

    expect($this->runtime->ready('feature-x-app', secure: true))->toBeTrue();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '--env', 'HOME=/home/outpost', '--user', 'outpost', '--workdir', '/app', 'feature-x-app',
        'curl', '--fail', '--insecure', '--silent', '--output', '/dev/null', 'https://127.0.0.1',
    ]);
});

it('gives up when a container never becomes ready', function () {
    Sleep::fake();

    Process::fake([
        processPattern('container', 'exec').' *'.processPattern('feature-x-app', 'curl').' *' => Process::result('', 'refused', 7),
    ]);

    expect($this->runtime->awaitReady('feature-x-app', 5))->toBeFalse();

    Sleep::assertSleptTimes(4);
});

it('reads the live publication domain from the runtime', function (string $output, ?string $expected) {
    Process::fake([
        processPattern('container', 'system', 'property', 'list', '--format', 'json') => Process::result($output),
    ]);

    expect((new Runtime)->publicationDomain())->toBe($expected);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'system', 'property', 'list', '--format', 'json',
    ]);
})->with([
    'dns domain' => ['{"dns":{"domain":"box"}}', 'box'],
    'trailing root label' => ['{"dns":{"domain":"outpost."}}', 'outpost'],
    'dns without domain' => ['{"dns":{}}', null],
    'empty domain' => ['{"dns":{"domain":""}}', null],
]);

it('rejects malformed runtime properties', function () {
    Process::fake([
        processPattern('container', 'system', 'property', 'list', '--format', 'json') => Process::result('not-json'),
    ]);

    expect(fn () => (new Runtime)->publicationDomain())
        ->toThrow(RuntimeException::class, 'Unable to parse the container system properties');
});

it('flushes the macos dns cache', function () {
    Process::fake();

    $this->runtime->flushDnsCache();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'dscacheutil', '-flushcache',
    ]);
});
