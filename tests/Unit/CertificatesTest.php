<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Certificates;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-certificates-'.Str::random(10);
    $this->certificates = new Certificates(
        new Filesystem,
        app('config'),
        $this->root,
    );

    config([
        'outpost.https' => 'auto',
        'outpost.tls.path' => '.outpost/tls',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('falls back to http in auto mode until certificates exist', function () {
    expect($this->certificates->enabled())->toBeFalse();

    File::ensureDirectoryExists($this->root.'/.outpost/tls');
    File::put($this->root.'/.outpost/tls/certificate.pem', 'certificate');
    File::put($this->root.'/.outpost/tls/key.pem', 'key');
    File::put($this->root.'/.outpost/tls/domain', "outpost\n");

    expect($this->certificates->enabled())->toBeTrue()
        ->and($this->certificates->directory())->toBe($this->root.'/.outpost/tls')
        ->and($this->certificates->certificatePath())->toBe($this->root.'/.outpost/tls/certificate.pem')
        ->and($this->certificates->keyPath())->toBe($this->root.'/.outpost/tls/key.pem');
});

it('falls back to http after the configured domain changes', function () {
    File::ensureDirectoryExists($this->root.'/.outpost/tls');
    File::put($this->root.'/.outpost/tls/certificate.pem', 'certificate');
    File::put($this->root.'/.outpost/tls/key.pem', 'key');
    File::put($this->root.'/.outpost/tls/domain', "outpost\n");
    config(['outpost.domain' => 'box']);

    expect($this->certificates->enabled())->toBeFalse();
});

it('supports explicitly disabling https', function () {
    config(['outpost.https' => false]);

    expect($this->certificates->enabled())->toBeFalse();
});

it('requires certificates when https is explicitly enabled', function () {
    config(['outpost.https' => true]);

    $this->certificates->enabled();
})->throws(RuntimeException::class, 'outpost:certify');

it('rejects unsupported https modes', function () {
    config(['outpost.https' => 'sometimes']);

    $this->certificates->enabled();
})->throws(RuntimeException::class, 'outpost.https');

it('creates and trusts a wildcard development certificate', function () {
    Process::fake();

    $this->certificates->create('outpost');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'mkcert', '-version',
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'mkcert', '-install',
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'mkcert',
        '-cert-file', $this->root.'/.outpost/tls/certificate.pem',
        '-key-file', $this->root.'/.outpost/tls/key.pem',
        'outpost', '*.outpost',
    ]);
});

it('explains how to install a missing mkcert binary', function () {
    Process::fake([
        processPattern('mkcert', '-version') => Process::result('', 'command not found', 127),
    ]);

    $this->certificates->create('outpost');
})->throws(RuntimeException::class, 'brew install mkcert');

it('surfaces certificate generation failures', function () {
    Process::fake([
        processPattern('mkcert', '-version') => Process::result('v1.4.4'),
        processPattern('mkcert', '-install') => Process::result('installed'),
        processPattern('mkcert', '-cert-file').' *' => Process::result('', 'certificate failed', 1),
    ]);

    $this->certificates->create('outpost');
})->throws(RuntimeException::class, 'certificate failed');

it('rejects unsafe domains and tls paths', function (array $configuration) {
    config($configuration);

    $this->certificates->create($configuration['outpost.domain'] ?? 'outpost');
})->with([
    'domain' => [['outpost.domain' => '../outpost']],
    'root path' => [['outpost.tls.path' => '/']],
    'empty path' => [['outpost.tls.path' => '']],
])->throws(RuntimeException::class);
