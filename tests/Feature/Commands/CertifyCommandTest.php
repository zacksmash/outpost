<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-certify-'.Str::random(10);

    config([
        'outpost.domain' => 'outpost',
        'outpost.https' => 'auto',
        'outpost.tls.path' => $this->root.'/tls',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('creates a trusted wildcard certificate', function () {
    Process::fake();

    $this->artisan('outpost:certify')
        ->expectsOutputToContain('HTTPS is ready')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'mkcert');
});

it('leaves an existing certificate alone without force', function () {
    File::ensureDirectoryExists($this->root.'/tls');
    File::put($this->root.'/tls/certificate.pem', 'certificate');
    File::put($this->root.'/tls/key.pem', 'key');
    File::put($this->root.'/tls/domain', "outpost\n");
    Process::fake();

    $this->artisan('outpost:certify')
        ->expectsOutputToContain('already ready')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('regenerates an existing certificate when forced', function () {
    File::ensureDirectoryExists($this->root.'/tls');
    File::put($this->root.'/tls/certificate.pem', 'certificate');
    File::put($this->root.'/tls/key.pem', 'key');
    File::put($this->root.'/tls/domain', "outpost\n");
    Process::fake();

    $this->artisan('outpost:certify', ['--force' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'mkcert');
});

it('reports certificate errors without hiding the remedy', function () {
    Process::fake([
        processPattern('mkcert', '-version') => Process::result('', 'not found', 127),
    ]);

    $this->artisan('outpost:certify')
        ->expectsOutputToContain('brew install mkcert')
        ->assertFailed();
});
