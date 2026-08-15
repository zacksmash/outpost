<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Certificates;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\Runtime;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->root = sys_get_temp_dir().'/outpost-doctor-'.Str::random(10);

    File::ensureDirectoryExists($this->root);
    File::put($this->root.'/.env.example', "APP_NAME=Example\n");
    File::put($this->root.'/composer.lock', '{}');
    File::ensureDirectoryExists($this->root.'/.outpost/tls');
    File::put($this->root.'/.outpost/tls/certificate.pem', 'certificate');
    File::put($this->root.'/.outpost/tls/key.pem', 'key');
    File::put($this->root.'/.outpost/tls/domain', "outpost\n");

    $certificates = new Certificates(
        new Filesystem,
        app('config'),
        $this->root,
    );

    $this->doctor = new Doctor(
        new Host,
        new Runtime,
        new Git($this->root),
        new Filesystem,
        $this->root,
        app('config'),
        $certificates,
    );
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function fakeHealthyDoctor(array $overrides = []): void
{
    Process::fake($overrides + [
        processPattern('uname', '-s') => Process::result("Darwin\n"),
        processPattern('uname', '-m') => Process::result("arm64\n"),
        processPattern('sw_vers', '-productVersion') => Process::result("27.0\n"),
        processPattern('container', '--version') => Process::result("container CLI version 1.2.2 (build: release)\n"),
        processPattern('container', 'system', 'status', '--format', 'json') => Process::result('{"status":"running"}'),
        processPattern('container', 'system', 'property', 'list', '--format', 'json') => Process::result('{"dns":{"domain":"outpost"}}'),
        processPattern('container', 'system', 'dns', 'list') => Process::result("DOMAIN\noutpost\n"),
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result(fakeImageInspect()),
        processPattern('git', 'rev-parse', 'HEAD') => Process::result("abc123\n"),
    ]);
}

it('passes a healthy supported environment', function () {
    fakeHealthyDoctor();

    $checks = collect($this->doctor->inspect())->keyBy('name');

    expect($checks)->toHaveCount(10)
        ->and($checks->every(fn (DoctorCheck $check): bool => $check->status === DoctorCheck::PASS))->toBeTrue()
        ->and($checks['Platform']->detail)->toBe('macOS 27.0 on arm64')
        ->and($checks['Runtime version']->detail)->toContain('1.2.2')
        ->and($checks['Publication domain']->detail)->toContain('[outpost]')
        ->and($checks['Base image']->detail)->toContain('[ghcr.io/zacksmash/outpost:0.2.1]');
});

it('rejects an image whose immutable identity cannot be recorded', function () {
    fakeHealthyDoctor([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result(json_encode([
            ['variants' => [['config' => ['config' => ['Labels' => [
                Runtime::IMAGE_RUNTIME_PATH_LABEL => Runtime::IMAGE_RUNTIME_PATH,
            ]]]]]],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $check = collect($this->doctor->inspect())->keyBy('name')[Doctor::BASE_IMAGE_CHECK];

    expect($check->status)->toBe(DoctorCheck::FAIL)
        ->and($check->detail)->toContain('immutable digest')
        ->and($check->remedy)->toContain('outpost:pull --force');
});

it('rejects an installed image without the required runtime path contract', function (?string $runtimePath) {
    $labels = $runtimePath === null ? [] : [Runtime::IMAGE_RUNTIME_PATH_LABEL => $runtimePath];

    fakeHealthyDoctor([
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result(json_encode([
            ['variants' => [['config' => ['config' => ['Labels' => $labels]]]]],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $check = collect($this->doctor->inspect())->keyBy('name')[Doctor::BASE_IMAGE_CHECK];

    expect($check->status)->toBe(DoctorCheck::FAIL)
        ->and($check->detail)->toContain('runtime-path contract')
        ->and($check->remedy)->toStartWith('Run: php artisan outpost:build --force')
        ->and($check->remedy)->toContain('outpost:pull --force')
        ->and($check->remedy)->toContain('outpost:build --force');
})->with([
    'missing label' => null,
    'old runtime path' => '/outpost',
]);

it('points an incompatible custom image configuration at the current shared image', function () {
    config(['outpost.image' => 'example.test/custom-outpost:latest']);

    fakeHealthyDoctor([
        processPattern('container', 'image', 'inspect', 'example.test/custom-outpost:latest') => Process::result('[{"variants":[{"config":{"config":{"Labels":{}}}}]}]'),
    ]);

    $check = collect($this->doctor->inspect())->keyBy('name')[Doctor::BASE_IMAGE_CHECK];

    expect($check->status)->toBe(DoctorCheck::FAIL)
        ->and($check->remedy)->toContain('ghcr.io/zacksmash/outpost:0.2.1')
        ->and($check->remedy)->toContain('outpost:build --force');
});

it('identifies machine setup required before instance creation', function () {
    expect($this->doctor->requiresSetup([
        DoctorCheck::failure(Doctor::BASE_IMAGE_CHECK, 'Missing.', 'Pull it.'),
    ]))->toBeTrue()
        ->and($this->doctor->requiresSetup([
            DoctorCheck::warning('Composer lock', 'Dependencies may drift.'),
        ]))->toBeFalse();

    config(['outpost.https' => false]);

    expect($this->doctor->requiresSetup([
        DoctorCheck::warning(Doctor::TLS_CHECK, 'HTTPS is disabled.'),
    ]))->toBeFalse();

    config(['outpost.https' => true]);

    expect($this->doctor->requiresSetup([
        DoctorCheck::failure(Doctor::TLS_CHECK, 'HTTPS is not prepared.', 'Prepare it.'),
    ]))->toBeTrue();
});

it('passes the local https check when https is disabled', function () {
    File::deleteDirectory($this->root.'/.outpost/tls');
    fakeHealthyDoctor();

    $checks = collect($this->doctor->inspect())->keyBy('name');

    expect($checks[Doctor::TLS_CHECK]->status)->toBe(DoctorCheck::PASS)
        ->and($checks[Doctor::TLS_CHECK]->detail)->toContain('HTTPS is disabled');
});

it('fails when required trusted https setup is missing', function () {
    config(['outpost.https' => true]);
    File::deleteDirectory($this->root.'/.outpost/tls');
    fakeHealthyDoctor();

    $checks = collect($this->doctor->inspect())->keyBy('name');

    expect($checks[Doctor::TLS_CHECK]->status)->toBe(DoctorCheck::FAIL)
        ->and($checks[Doctor::TLS_CHECK]->remedy)->toContain('brew install mkcert');
});

it('fails unsupported platforms and old runtime versions', function () {
    fakeHealthyDoctor([
        processPattern('uname', '-m') => Process::result("x86_64\n"),
        processPattern('sw_vers', '-productVersion') => Process::result("25.6\n"),
        processPattern('container', '--version') => Process::result("container CLI version 1.1.4 (build: release)\n"),
    ]);

    $checks = collect($this->doctor->inspect())->keyBy('name');

    expect($checks['Platform']->status)->toBe(DoctorCheck::FAIL)
        ->and($checks['Platform']->detail)->toContain('macOS 25.6 on x86_64')
        ->and($checks['Runtime version']->status)->toBe(DoctorCheck::FAIL)
        ->and($checks['Runtime version']->remedy)->toContain('1.2.x');
});

it('warns without failing on a newer unverified runtime minor', function () {
    fakeHealthyDoctor([
        processPattern('container', '--version') => Process::result("container CLI version 1.3.0 (build: release)\n"),
    ]);

    $checks = collect($this->doctor->inspect())->keyBy('name');

    expect($checks['Runtime version']->status)->toBe(DoctorCheck::WARNING)
        ->and($checks->contains(fn (DoctorCheck $check): bool => $check->status === DoctorCheck::FAIL))->toBeFalse();
});

it('reports stopped runtimes without attempting dependent checks', function () {
    fakeHealthyDoctor([
        processPattern('container', 'system', 'status', '--format', 'json') => Process::result('{"status":"stopped"}'),
    ]);

    $checks = collect($this->doctor->inspect())->keyBy('name');

    expect($checks['Runtime']->status)->toBe(DoctorCheck::FAIL)
        ->and($checks['Runtime']->remedy)->toBe('Run: container system start')
        ->and($checks)->not->toHaveKeys(['Publication domain', 'DNS resolver', 'Base image']);
});

it('reports a missing container cli while still checking the project', function () {
    fakeHealthyDoctor([
        processPattern('container', '--version') => Process::result('', 'command not found', 127),
    ]);

    $checks = collect($this->doctor->inspect())->keyBy('name');

    expect($checks['Runtime version']->status)->toBe(DoctorCheck::FAIL)
        ->and($checks['Runtime version']->detail)->toContain('command not found')
        ->and($checks)->not->toHaveKeys(['Runtime', 'Publication domain', 'DNS resolver', 'Base image'])
        ->and($checks)->toHaveKeys(['Git repository', 'Environment template', 'Composer lock']);
});

it('reports domain, resolver, image, and project problems with fixes', function () {
    File::delete($this->root.'/.env.example');
    File::delete($this->root.'/composer.lock');

    fakeHealthyDoctor([
        processPattern('container', 'system', 'property', 'list', '--format', 'json') => Process::result('{"dns":{"domain":"box"}}'),
        processPattern('container', 'system', 'dns', 'list') => Process::result("DOMAIN\nbox\n"),
        processPattern('container', 'image', 'inspect', 'ghcr.io/zacksmash/outpost:0.2.1') => Process::result('', 'not found', 1),
        processPattern('git', 'rev-parse', 'HEAD') => Process::result('', 'unknown revision', 128),
    ]);

    $checks = collect($this->doctor->inspect())->keyBy('name');

    expect($checks['Publication domain']->status)->toBe(DoctorCheck::FAIL)
        ->and($checks['Publication domain']->remedy)->toContain('OUTPOST_DOMAIN=box')
        ->and($checks['DNS resolver']->status)->toBe(DoctorCheck::FAIL)
        ->and($checks['Base image']->remedy)->toContain('outpost:build')
        ->and($checks['Git repository']->status)->toBe(DoctorCheck::FAIL)
        ->and($checks['Environment template']->status)->toBe(DoctorCheck::FAIL)
        ->and($checks['Composer lock']->status)->toBe(DoctorCheck::WARNING);
});
