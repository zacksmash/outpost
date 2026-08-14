<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;
use Zacksmash\Outpost\Runtime;

beforeEach(function () {
    Process::preventStrayProcesses();
});

it('finishes immediately when outpost is already ready', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::pass('Platform', 'macOS 27.0 on arm64'),
        DoctorCheck::pass('Runtime', 'The Apple container system is running.'),
        DoctorCheck::pass('Base image', 'The [outpost-base] image is available.'),
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

it('offers to build a missing base image and verifies the result', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::failure('Base image', 'The [outpost-base] image is missing.', 'Run: php artisan outpost:build')],
        [DoctorCheck::pass('Base image', 'The [outpost-base] image is available.')],
    );

    app()->instance(Doctor::class, $doctor);

    Process::fake([
        processPattern('container', 'image', 'inspect', 'outpost-base') => Process::result('', 'not found', 1),
        processPattern('container', 'build').' *' => Process::result('built'),
    ]);

    $this->artisan('outpost:install')
        ->expectsConfirmation('Build the shared [outpost-base] image now?', 'yes')
        ->expectsOutputToContain('Outpost is ready')
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
        DoctorCheck::failure('Base image', 'The [outpost-base] image is missing.', 'Run: php artisan outpost:build'),
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
