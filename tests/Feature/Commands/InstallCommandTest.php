<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;
use Zacksmash\Outpost\Runtime;
use Zacksmash\Outpost\RuntimeConfiguration;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-install-'.Str::random(10);

    config([
        'outpost.domain' => 'outpost',
        'outpost.https' => false,
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
        DoctorCheck::pass('Base image', 'The [ghcr.io/zacksmash/outpost:0.5.1] image is available.'),
    ]);

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install')
        ->expectsOutputToContain('Outpost is ready')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('explains that local setup does not rebuild an already compatible image', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::pass(Doctor::BASE_IMAGE_CHECK, 'The configured image is compatible.'),
    ]);

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install', ['--local' => true])
        ->expectsOutputToContain('--local is not rebuilding it')
        ->expectsOutputToContain('outpost:build --force')
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
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.5.1') => Process::result('', 'not found', 1),
        processPattern('container', 'image', 'pull', 'ghcr.io/zacksmash/outpost:0.5.1') => Process::result('pulled'),
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
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.5.1') => Process::result('', 'not found', 1),
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
    config(['outpost.https' => true]);

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
    $runtime->shouldReceive('pull')->once()->with('ghcr.io/zacksmash/outpost:0.5.1', Mockery::type('callable'));

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
    config(['outpost.https' => true]);

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

it('enables trusted https in the application environment when explicitly requested', function (string $environment, string $expected) {
    app()->useEnvironmentPath($this->root);
    File::ensureDirectoryExists($this->root);
    File::put($this->root.'/.env', $environment);
    File::ensureDirectoryExists($this->root.'/tls');
    File::put($this->root.'/tls/domain', "outpost\n");
    File::put($this->root.'/tls/trusted', "mkcert\n");

    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->twice()->andReturn(
        [DoctorCheck::warning(
            Doctor::TLS_CHECK,
            'Trusted HTTPS is prepared but disabled.',
            'Run: php artisan outpost:install --https --force',
        )],
        [DoctorCheck::pass(Doctor::TLS_CHECK, 'Trusted HTTPS is ready.')],
    );

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install', ['--force' => true, '--https' => true])
        ->expectsOutputToContain('Enable trusted local HTTPS for new instances')
        ->assertSuccessful();

    expect(File::get($this->root.'/.env'))
        ->toBe($expected)
        ->and(config('outpost.https'))->toBeTrue();

    Process::assertNothingRan();
})->with([
    'replace a disabled preference' => [
        "APP_NAME=Outpost\nOUTPOST_HTTPS=false\n",
        "APP_NAME=Outpost\nOUTPOST_HTTPS=true\n",
    ],
    'append a missing preference' => [
        "APP_NAME=Outpost\n",
        "APP_NAME=Outpost\nOUTPOST_HTTPS=true\n",
    ],
]);

it('explains when trusted https cannot be enabled without an environment file', function () {
    app()->useEnvironmentPath($this->root);
    File::ensureDirectoryExists($this->root.'/tls');
    File::put($this->root.'/tls/domain', "outpost\n");
    File::put($this->root.'/tls/trusted', "mkcert\n");

    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::warning(
            Doctor::TLS_CHECK,
            'Trusted HTTPS is prepared but disabled.',
            'Run: php artisan outpost:install --https --force',
        ),
    ]);

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install', ['--force' => true, '--https' => true])
        ->expectsOutputToContain('because [.env] does not exist')
        ->assertFailed();

    Process::assertNothingRan();
});

it('refuses non-interactive setup without the explicit force option', function () {
    config(['outpost.https' => true]);

    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS falls back to HTTP.', 'Run outpost:certify'),
    ]);

    app()->instance(Doctor::class, $doctor);

    Process::fake();

    $this->artisan('outpost:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Non-interactive setup needs the explicit --force option.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('restarts the runtime even when writing the publication domain fails', function () {
    $doctor = Mockery::mock(Doctor::class);
    $doctor->shouldReceive('inspect')->once()->andReturn([
        DoctorCheck::failure(Doctor::PUBLICATION_DOMAIN_CHECK, 'The running system publishes [test].', 'Configure outpost.'),
    ]);

    $runtime = Mockery::mock(Runtime::class);
    $runtime->shouldReceive('stopSystem')->once();
    $runtime->shouldReceive('startSystem')->once();

    $configuration = Mockery::mock(RuntimeConfiguration::class);
    $configuration->shouldReceive('setDomain')->once()
        ->andThrow(new RuntimeException('Unable to update the Apple container configuration at [config.toml].'));

    app()->instance(Doctor::class, $doctor);
    app()->instance(Runtime::class, $runtime);
    app()->instance(RuntimeConfiguration::class, $configuration);

    $this->artisan('outpost:install')
        ->expectsConfirmation('Prepare Outpost now?', 'yes')
        ->expectsOutputToContain('Unable to update the Apple container configuration')
        ->assertFailed();
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
