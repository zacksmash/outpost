<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;

it('reports successful checks and non-blocking warnings', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::pass('Platform', 'macOS 27.0 on arm64'),
        DoctorCheck::warning('Runtime version', 'Apple container 1.3.0 is newer than the verified 1.2.x line.'),
    ]);

    app()->instance(Doctor::class, $doctor);

    $exit = Artisan::call('outpost:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('PASS')
        ->and($output)->toContain('Platform')
        ->and($output)->toContain('WARN')
        ->and($output)->toContain('newer than the verified')
        ->and($output)->toContain('System Settings > Privacy & Security > Local Network')
        ->and($output)->toContain('CLI tools')
        ->and($output)->toContain('outpost:exec <name> -- curl --fail --silent --show-error http://localhost')
        ->and($output)->toContain('--insecure https://localhost');
});

it('fails when required checks fail and prints their remedies', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure(
            'Runtime',
            'The Apple container system is stopped.',
            'Run: container system start',
        ),
        DoctorCheck::pass('Project', 'Git repository with at least one commit'),
    ]);

    app()->instance(Doctor::class, $doctor);

    $exit = Artisan::call('outpost:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('FAIL')
        ->and($output)->toContain('The Apple container system is stopped.')
        ->and($output)->toContain('Run: container system start');
});

it('provides a complete json diagnostic report', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::pass('Platform', 'macOS 27.0 on arm64'),
        DoctorCheck::warning(
            'Runtime version',
            'Apple container 1.3.0 is newer than the verified line.',
            'Install the latest supported runtime when problems occur.',
        ),
    ]);

    app()->instance(Doctor::class, $doctor);

    $exit = Artisan::call('outpost:doctor', ['--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output)->toBe([
            'ready' => true,
            'checks' => [
                [
                    'name' => 'Platform',
                    'status' => 'PASS',
                    'detail' => 'macOS 27.0 on arm64',
                    'remedy' => null,
                ],
                [
                    'name' => 'Runtime version',
                    'status' => 'WARN',
                    'detail' => 'Apple container 1.3.0 is newer than the verified line.',
                    'remedy' => 'Install the latest supported runtime when problems occur.',
                ],
            ],
        ]);
});

it('returns unsuccessful json when a diagnostic check blocks Outpost', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure('Runtime', 'The runtime is stopped.', 'Run: container system start'),
    ]);

    app()->instance(Doctor::class, $doctor);

    $exit = Artisan::call('outpost:doctor', ['--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($output['ready'])->toBeFalse()
        ->and($output['checks'][0]['remedy'])->toBe('Run: container system start');
});
