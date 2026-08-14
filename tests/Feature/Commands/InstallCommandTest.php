<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;
use Zacksmash\Outpost\Runtime;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-install-'.Str::random(10);

    config([
        'outpost.domain' => 'outpost',
        'outpost.https' => 'auto',
        'outpost.tls.path' => $this->root.'/tls',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('finishes immediately when outpost is already ready', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::pass('Platform', 'macOS 27.0 on arm64'),
        DoctorCheck::pass('Runtime', 'The Apple container system is running.'),
        DoctorCheck::pass('Base image', 'The [ghcr.io/zacksmash/outpost:0.1.0] image is available.'),
    ]);

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('offers to start a stopped runtime and verifies the result', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::failure('Runtime', 'The Apple container system is stopped.', 'Run: container system start')],
        [DoctorCheck::pass('Runtime', 'The Apple container system is running.')],
    );

    $runtime = Mockery::mock(Runtime::class);
    $runtime->shouldReceive('startSystem')->once();

    app()->instance(Doctor::class, $doctor);
    app()->instance(Runtime::class, $runtime);

    $this->artisan('outpost:install')
        ->expectsConfirmation('Start the Apple container system now?', 'yes')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();
});

it('leaves a stopped runtime untouched when the action is declined', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure('Runtime', 'The Apple container system is stopped.', 'Run: container system start'),
    ]);

    $runtime = Mockery::mock(Runtime::class);
    $runtime->shouldNotReceive('startSystem');

    app()->instance(Doctor::class, $doctor);
    app()->instance(Runtime::class, $runtime);

    $this->artisan('outpost:install')
        ->expectsConfirmation('Start the Apple container system now?', 'no')
        ->expectsOutputToContain('Run: container system start')
        ->assertFailed();
});

it('offers to pull a missing base image and verifies the result', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::failure('Base image', 'The versioned image is missing.', 'Run: php artisan outpost:pull')],
        [DoctorCheck::pass('Base image', 'The versioned image is available.')],
    );

    app()->instance(Doctor::class, $doctor);

    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.0') => Process::result('', 'not found', 1),
        processPattern('container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.1.0') => Process::result('pulled'),
    ]);

    $this->artisan('outpost:install')
        ->expectsConfirmation('Pull the shared [ghcr.io/zacksmash/outpost:0.1.0] image now?', 'yes')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'pull');
});

it('builds the base image locally when requested', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::failure('Base image', 'The versioned image is missing.', 'Run: php artisan outpost:pull')],
        [DoctorCheck::pass('Base image', 'The versioned image is available.')],
    );

    app()->instance(Doctor::class, $doctor);

    Process::fake([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.1.0') => Process::result('', 'not found', 1),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:install', ['--local' => true])
        ->expectsConfirmation('Build the shared [ghcr.io/zacksmash/outpost:0.1.0] image locally now?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'build');
});

it('prints manual remedies without applying privileged changes', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure(
            'DNS resolver',
            'The [outpost] resolver is not registered.',
            'Run: sudo container system dns create outpost',
        ),
    ]);

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install')
        ->expectsOutputToContain('sudo container system dns create outpost')
        ->expectsOutputToContain('Run [php artisan outpost:install] again')
        ->assertFailed();

    Process::assertNothingRan();
});

it('does not build an image while the runtime contract is blocked', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure('Runtime version', 'Apple container 1.1.4 is unsupported.', 'Upgrade to 1.2.x.'),
        DoctorCheck::failure('Base image', 'The versioned image is missing.', 'Run: php artisan outpost:pull'),
    ]);

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install')->assertFailed();

    Process::assertNothingRan();
});

it('applies safe setup steps without prompting when forced', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::failure('Runtime', 'The Apple container system is stopped.', 'Run: container system start')],
        [DoctorCheck::pass('Runtime', 'The Apple container system is running.')],
    );

    $runtime = Mockery::mock(Runtime::class);
    $runtime->shouldReceive('startSystem')->once();

    app()->instance(Doctor::class, $doctor);
    app()->instance(Runtime::class, $runtime);

    $this->artisan('outpost:install', ['--force' => true])
        ->doesntExpectOutputToContain('Start the Apple container system now?')
        ->assertSuccessful();
});

it('offers to prepare trusted https when setup is missing', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS falls back to HTTP.', 'Run outpost:certify')],
        [DoctorCheck::pass(Doctor::TLS_CHECK, 'Trusted HTTPS is ready.')],
    );

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install')
        ->expectsConfirmation('Prepare trusted local HTTPS now?', 'yes')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['mkcert', '-install']);
});

it('does not modify the trust store during forced setup unless https is explicit', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS falls back to HTTP.', 'Run outpost:certify'),
    ]);

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install', ['--force' => true])
        ->doesntExpectOutputToContain('Create and trust')
        ->assertSuccessful();

    Process::assertNothingRan();
});
