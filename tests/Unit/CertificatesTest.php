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
        'outpost.domain' => 'outpost',
        'outpost.https' => 'auto',
        'outpost.tls.path' => '.outpost/tls',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('falls back to http in auto mode until trusted https is prepared', function () {
    expect($this->certificates->enabled())->toBeFalse();

    File::ensureDirectoryExists($this->root.'/.outpost/tls');
    File::put($this->root.'/.outpost/tls/domain', "outpost\n");
    File::put($this->root.'/.outpost/tls/trusted', "mkcert\n");

    expect($this->certificates->enabled())->toBeTrue()
        ->and($this->certificates->directory())->toBe($this->root.'/.outpost/tls')
        ->and($this->certificates->domainPath())->toBe($this->root.'/.outpost/tls/domain')
        ->and($this->certificates->trustedPath())->toBe($this->root.'/.outpost/tls/trusted');
});

it('falls back to http after the configured domain changes', function () {
    File::ensureDirectoryExists($this->root.'/.outpost/tls');
    File::put($this->root.'/.outpost/tls/domain', "outpost\n");
    File::put($this->root.'/.outpost/tls/trusted', "mkcert\n");
    config(['outpost.domain' => 'box']);

    expect($this->certificates->enabled())->toBeFalse();
});

it('recognizes a legacy shared certificate as prepared setup', function () {
    File::ensureDirectoryExists($this->root.'/.outpost/tls');
    File::put($this->root.'/.outpost/tls/domain', "outpost\n");
    File::put($this->root.'/.outpost/tls/certificate.pem', 'legacy certificate');
    File::put($this->root.'/.outpost/tls/key.pem', 'legacy key');

    expect($this->certificates->enabled())->toBeTrue();
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

it('installs the local authority without creating a wildcard certificate', function () {
    Process::fake();

    $this->certificates->create('outpost');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'mkcert', '-version',
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'mkcert', '-install',
    ]);
    Process::assertDidntRun(fn (PendingProcess $process) => in_array('-cert-file', $process->command, true));

    expect(File::get($this->root.'/.outpost/tls/domain'))->toBe("outpost\n")
        ->and(File::get($this->root.'/.outpost/tls/trusted'))->toBe("mkcert\n");
});

it('creates a certificate containing only the exact instance hostname', function () {
    File::ensureDirectoryExists($this->root.'/.outpost/tls');
    File::put($this->root.'/.outpost/tls/domain', "outpost\n");
    File::put($this->root.'/.outpost/tls/trusted', "mkcert\n");
    Process::fake();

    $directory = $this->root.'/.outpost/billing/runtime/tls';

    $this->certificates->createForHost('billing-app.outpost', $directory);

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'mkcert',
        '-cert-file', $directory.'/certificate.pem',
        '-key-file', $directory.'/key.pem',
        'billing-app.outpost',
    ]);

    Process::assertDidntRun(fn (PendingProcess $process) => in_array('*.outpost', $process->command, true));

    expect(File::isDirectory($directory))->toBeTrue();
});

it('explains how to install a missing mkcert binary', function () {
    Process::fake([
        processPattern('mkcert', '-version') => Process::result('', 'command not found', 127),
    ]);

    $this->certificates->create('outpost');
})->throws(RuntimeException::class, 'brew install mkcert');

it('surfaces exact certificate generation failures', function () {
    File::ensureDirectoryExists($this->root.'/.outpost/tls');
    File::put($this->root.'/.outpost/tls/domain', "outpost\n");
    File::put($this->root.'/.outpost/tls/trusted', "mkcert\n");

    Process::fake([
        processPattern('mkcert', '-cert-file').' *' => Process::result('', 'certificate failed', 1),
    ]);

    $this->certificates->createForHost(
        'billing-app.outpost',
        $this->root.'/.outpost/billing/runtime/tls',
    );
})->throws(RuntimeException::class, 'certificate failed');

it('refuses to issue a certificate outside the configured domain', function () {
    Process::fake();

    $this->certificates->createForHost(
        'billing-app.example.test',
        $this->root.'/.outpost/billing/runtime/tls',
    );
})->throws(RuntimeException::class, 'must be an exact hostname beneath [outpost]');

it('rejects unsafe domains and tls paths', function (array $configuration) {
    config($configuration);

    $this->certificates->create($configuration['outpost.domain'] ?? 'outpost');
})->with([
    'domain' => [['outpost.domain' => '../outpost']],
    'root path' => [['outpost.tls.path' => '/']],
    'empty path' => [['outpost.tls.path' => '']],
])->throws(RuntimeException::class);
