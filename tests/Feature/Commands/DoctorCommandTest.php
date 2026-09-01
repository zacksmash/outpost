<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;

it('shows the ready summary and hides troubleshooting text on a healthy run', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::pass('Platform', 'macOS 27.0 on arm64'),
        DoctorCheck::pass('Runtime', 'The Apple container system is running.'),
    ]);

    app()->instance(Doctor::class, $doctor);

    $exit = Artisan::call('outpost:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Outpost is ready')
        ->and($output)->toContain('Platform')
        ->and($output)->toContain('macOS 27.0 on arm64')
        ->and($output)->toContain('Runtime')
        ->and($output)->toContain('The Apple container system is running.')
        ->and($output)->toContain('Create an instance with')
        ->and($output)->toContain('2 checks passed')
        ->and($output)->not->toContain('┬') // no table column borders
        ->and($output)->not->toContain('Host access')
        ->and($output)->not->toContain('Container probes')
        ->and($output)->not->toContain('Service web endpoints');
});

it('shows the troubleshooting text on a healthy run with -v', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::pass('Platform', 'macOS 27.0 on arm64'),
    ]);

    app()->instance(Doctor::class, $doctor);

    $exit = Artisan::call('outpost:doctor', ['-v' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Outpost is ready')
        ->and($output)->toContain('Host access')
        ->and($output)->toContain('If browsers or CLI tools cannot reach container addresses')
        ->and($output)->toContain('Privacy & Security > Local Network')
        ->and($output)->toContain('Container probes')
        ->and($output)->toContain('The published hostname does not resolve inside its own')
        ->and($output)->toContain('outpost:exec <name> -- curl --fail')
        ->and($output)->toContain('--silent --show-error http://localhost')
        ->and($output)->toContain('--insecure https://localhost')
        ->and($output)->toContain('Service web endpoints')
        ->and($output)->toContain('Service web endpoints use the same scheme as the')
        ->and($output)->toContain('--insecure https://localhost:8025')
        ->and($output)->toContain('outpost:info <name>');
});

it('shows the remedy and troubleshooting text for a failing check, and exits FAILURE', function () {
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
        ->and($output)->toContain('Runtime (FAIL)')
        ->and($output)->not->toContain('Project (FAIL)')
        ->and($output)->not->toContain('Project (WARN)')
        ->and($output)->toContain('The Apple container system is stopped.')
        ->and($output)->toContain('1 blocking issue')
        ->and($output)->toContain('Run: container system start')
        ->and($output)->toContain('Host access')
        ->and($output)->toContain('Container probes')
        ->and($output)->toContain('Service web endpoints');
});

it('exits SUCCESS and uses warning styling for a warning-only run', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::warning(
            'Runtime version',
            'Apple container 1.4.0 is newer than the verified 1.3.x line.',
            'If Outpost behaves unexpectedly, install the latest Apple container 1.3.x release.',
        ),
        DoctorCheck::pass('Platform', 'macOS 27.0 on arm64'),
    ]);

    app()->instance(Doctor::class, $doctor);

    $exit = Artisan::call('outpost:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Runtime version (WARN)')
        ->and($output)->not->toContain('Runtime version (FAIL)')
        ->and($output)->not->toContain('Platform (WARN)')
        ->and($output)->toContain('1 warning')
        ->and($output)->not->toContain('blocking issue')
        ->and($output)->toContain('If Outpost behaves unexpectedly, install the latest Apple')
        ->and($output)->toContain('Host access');
});

it('pluralizes blocking issue and warning counts correctly', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure('Runtime', 'The runtime is stopped.', 'Run: container system start'),
        DoctorCheck::failure('DNS resolver', 'Not registered.', 'Run: sudo container system dns create outpost'),
        DoctorCheck::warning('Composer lock', 'No lock file.', 'Run composer update and commit [composer.lock].'),
        DoctorCheck::warning('Local HTTPS', 'HTTPS is prepared but disabled.', 'php artisan outpost:certify'),
    ]);

    app()->instance(Doctor::class, $doctor);

    $exit = Artisan::call('outpost:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('2 blocking issues')
        ->and($output)->toContain('2 warnings')
        ->and($output)->toContain('Runtime (FAIL)')
        ->and($output)->toContain('DNS resolver (FAIL)')
        ->and($output)->toContain('Composer lock (WARN)')
        ->and($output)->toContain('Local HTTPS (WARN)');
});

it('provides a complete json diagnostic report unchanged by the presentation rework', function () {
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
