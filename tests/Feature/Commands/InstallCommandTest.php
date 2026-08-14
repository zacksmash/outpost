<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\ApplicationHttps;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;
use Zacksmash\Outpost\Runtime;
use Zacksmash\Outpost\RuntimeConfiguration;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-install-'.Str::random(10);

    config([
        'outpost.domain' => 'outpost',
        'outpost.https' => 'auto',
        'outpost.tls.path' => $this->root.'/tls',
    ]);

    $this->applicationHttps = Mockery::mock(ApplicationHttps::class);
    $this->applicationHttps->shouldReceive('detected')->byDefault()->andReturnTrue();
    app()->instance(ApplicationHttps::class, $this->applicationHttps);
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
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
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
        ->expectsConfirmation('Prepare Outpost now?', 'no')
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
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
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
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'build');
});

it('registers the local dns resolver after one setup confirmation', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::failure(
            Doctor::DNS_RESOLVER_CHECK,
            'The [outpost] resolver is not registered.',
            'Run: sudo container system dns create outpost',
        )],
        [DoctorCheck::pass(Doctor::DNS_RESOLVER_CHECK, 'The [outpost] resolver is registered.')],
    );

    $runtime = Mockery::mock(Runtime::class);
    $runtime->shouldReceive('registerDomain')->once()->with('outpost');

    app()->instance(Doctor::class, $doctor);
    app()->instance(Runtime::class, $runtime);

    Process::fake();

    $this->artisan('outpost:install')
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();
});

it('does not wait for administrator authentication in a non-interactive run', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure(
            Doctor::DNS_RESOLVER_CHECK,
            'The [outpost] resolver is not registered.',
            'Run: sudo container system dns create outpost',
        ),
    ]);

    $runtime = Mockery::mock(Runtime::class);
    $runtime->shouldNotReceive('registerDomain');

    app()->instance(Doctor::class, $doctor);
    app()->instance(Runtime::class, $runtime);

    $this->artisan('outpost:install', [
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('sudo container system dns create outpost')
        ->assertFailed();
});

it('configures the publication domain and restarts the runtime', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::failure(
            Doctor::PUBLICATION_DOMAIN_CHECK,
            'The running system publishes [test].',
            'Configure outpost.',
        )],
        [DoctorCheck::pass(Doctor::PUBLICATION_DOMAIN_CHECK, 'The live [outpost] domain matches.')],
    );

    $runtime = Mockery::mock(Runtime::class);
    $runtime->shouldReceive('stopSystem')->once();
    $runtime->shouldReceive('startSystem')->once();

    $configuration = Mockery::mock(RuntimeConfiguration::class);
    $configuration->shouldReceive('setDomain')->once()->with('outpost');

    app()->instance(Doctor::class, $doctor);
    app()->instance(Runtime::class, $runtime);
    app()->instance(RuntimeConfiguration::class, $configuration);

    $this->artisan('outpost:install')
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();
});

it('uses one confirmation for networking https and the shared image', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->times(3)->andReturn(
        [
            DoctorCheck::failure(Doctor::PUBLICATION_DOMAIN_CHECK, 'Publishes [test].', 'Configure it.'),
            DoctorCheck::failure(Doctor::DNS_RESOLVER_CHECK, 'Resolver missing.', 'Register it.'),
            DoctorCheck::failure(Doctor::BASE_IMAGE_CHECK, 'Image missing.', 'Pull it.'),
            DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS not prepared.', 'Prepare it.'),
        ],
        [
            DoctorCheck::pass(Doctor::PUBLICATION_DOMAIN_CHECK, 'Publishes [outpost].'),
            DoctorCheck::failure(Doctor::DNS_RESOLVER_CHECK, 'Resolver missing.', 'Register it.'),
            DoctorCheck::failure(Doctor::BASE_IMAGE_CHECK, 'Image missing.', 'Pull it.'),
            DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS not prepared.', 'Prepare it.'),
        ],
        [
            DoctorCheck::pass(Doctor::PUBLICATION_DOMAIN_CHECK, 'Publishes [outpost].'),
            DoctorCheck::pass(Doctor::DNS_RESOLVER_CHECK, 'Resolver registered.'),
            DoctorCheck::pass(Doctor::BASE_IMAGE_CHECK, 'Image available.'),
            DoctorCheck::pass(Doctor::TLS_CHECK, 'HTTPS ready.'),
        ],
    );

    $runtime = Mockery::mock(Runtime::class);
    $runtime->shouldReceive('stopSystem')->once();
    $runtime->shouldReceive('startSystem')->once();
    $runtime->shouldReceive('registerDomain')->once()->with('outpost');
    $runtime->shouldReceive('pull')->once()->with('ghcr.io/zacksmash/outpost:0.1.0', Mockery::type('callable'));

    $configuration = Mockery::mock(RuntimeConfiguration::class);
    $configuration->shouldReceive('setDomain')->once()->with('outpost');

    app()->instance(Doctor::class, $doctor);
    app()->instance(Runtime::class, $runtime);
    app()->instance(RuntimeConfiguration::class, $configuration);

    Process::fake();

    $this->artisan('outpost:install')
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['mkcert', '-install']);
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
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['mkcert', '-install']);
});

it('does not prepare trusted https in auto mode when the primary application uses http', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS is not prepared.', 'Prepare it.'),
    ]);
    $this->applicationHttps->shouldReceive('detected')->once()->andReturnFalse();

    app()->instance(Doctor::class, $doctor);
    Process::fake();

    $this->artisan('outpost:install')
        ->doesntExpectOutputToContain('Prepare trusted local HTTPS')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('keeps https optional in auto mode when mkcert is not installed', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [
            DoctorCheck::failure(Doctor::BASE_IMAGE_CHECK, 'Image missing.', 'Pull it.'),
            DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS not prepared.', 'Install mkcert.'),
        ],
        [
            DoctorCheck::pass(Doctor::BASE_IMAGE_CHECK, 'Image available.'),
            DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS not prepared.', 'Install mkcert.'),
        ],
    );

    app()->instance(Doctor::class, $doctor);

    Process::fake([
        processPattern('mkcert', '-version') => Process::result('', 'not found', 127),
        processPattern('container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.1.0') => Process::result('pulled'),
    ]);

    $this->artisan('outpost:install')
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => $process->command === ['mkcert', '-install']);
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
