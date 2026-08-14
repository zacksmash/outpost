<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
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

it('pulls an image from an oci registry', function () {
    Process::fake();

    $this->runtime->pull('ghcr.io/zacksmash/outpost:0.1.0');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.1.0',
    ]);
});

it('surfaces the real error when an image pull fails', function () {
    Process::fake([
        processPattern('container', 'image', 'pull').' *' => Process::result('', 'denied', 1),
    ]);

    $this->runtime->pull('ghcr.io/zacksmash/outpost:0.1.0');
})->throws(RuntimeException::class, 'Unable to pull the [ghcr.io/zacksmash/outpost:0.1.0] image: denied');

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
        '/host/runtime:/outpost:ro',
    ]);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'run', '--detach', '--name', 'feature-x-app', '--dns', '1.1.1.1',
        '--volume', '/host/app:/app',
        '--volume', '/host/runtime:/outpost:ro',
        'outpost-base',
    ]);
});

it('surfaces the real error when a boot fails', function () {
    Process::fake([
        processPattern('container', 'run').' *' => Process::result('', 'no such image', 125),
    ]);

    $this->runtime->boot('feature-x-app', 'outpost-base', '1.1.1.1', []);
})->throws(RuntimeException::class, 'Unable to boot the container [feature-x-app]: no such image');

it('starts, stops, and deletes containers', function (string $method, string $verb) {
    Process::fake();

    $this->runtime->{$method}('feature-x-app');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', $verb, 'feature-x-app',
    ]);
})->with([
    'start' => ['start', 'start'],
    'stop' => ['stop', 'stop'],
    'delete' => ['delete', 'delete'],
]);

it('surfaces the real error when a lifecycle command fails', function (string $method) {
    Process::fake([
        "'container'*" => Process::result('', 'went sideways', 1),
    ]);

    expect(fn () => $this->runtime->{$method}('feature-x-app'))
        ->toThrow(RuntimeException::class, 'went sideways');
})->with(['start', 'stop', 'delete']);

it('executes commands inside a container and returns the raw result', function () {
    Process::fake([
        processPattern('container', 'exec', 'feature-x-app', 'php', 'artisan', 'migrate', '--force') => Process::result('migrated', 'warning', 2),
    ]);

    $result = $this->runtime->exec('feature-x-app', ['php', 'artisan', 'migrate', '--force']);

    expect($result->exitCode())->toBe(2)
        ->and(trim($result->output()))->toBe('migrated')
        ->and(trim($result->errorOutput()))->toBe('warning');
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

it('opens a shell and passes the exit code through', function () {
    $tty = Symfony\Component\Process\Process::isTtySupported();

    Process::fake([
        processPattern('container', 'exec', '-i').' *' => Process::result('', '', 3),
    ]);

    expect($this->runtime->shell('feature-x-app'))->toBe(3);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'container', 'exec', '-i', ...($tty ? ['-t'] : []), 'feature-x-app', 'bash',
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
        processPattern('container', 'exec', 'feature-x-app', 'curl').' *' => Process::sequence()
            ->push(Process::result('', 'refused', 7))
            ->push(Process::result('', 'refused', 7))
            ->push(Process::result('')),
    ]);

    expect($this->runtime->awaitReady('feature-x-app', 30))->toBeTrue();

    Sleep::assertSleptTimes(2);
});

it('gives up when a container never becomes ready', function () {
    Sleep::fake();

    Process::fake([
        processPattern('container', 'exec', 'feature-x-app', 'curl').' *' => Process::result('', 'refused', 7),
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
