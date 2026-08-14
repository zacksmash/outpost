<?php

declare(strict_types=1);

use Zacksmash\Outpost\Supervisord;

it('always runs php-fpm and nginx', function () {
    $config = (new Supervisord)->generate(fakeManifest(services: []));

    expect($config)->toContain('[program:php-fpm]')
        ->and($config)->toContain('command=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/php/8.4/fpm/php-fpm.conf')
        ->and($config)->toContain('[program:nginx]')
        ->and($config)->toContain("command=/usr/sbin/nginx -g 'daemon off;'")
        ->and($config)->not->toContain('[program:mysql]')
        ->and($config)->not->toContain('[program:pgsql]')
        ->and($config)->not->toContain('[program:redis]')
        ->and($config)->not->toContain('[program:mailpit]');
});

it('pins php-fpm to the instance php version', function () {
    expect((new Supervisord)->generate(fakeManifest(php: '8.5', services: [])))
        ->toContain('command=/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /etc/php/8.5/fpm/php-fpm.conf');
});

it('does not run php fpm when octane serves the application', function () {
    $config = (new Supervisord)->generate(fakeManifest(server: 'octane', processes: ['octane']), [
        'octane' => ['php8.5', 'artisan', 'octane:start'],
    ]);

    expect($config)->toContain('[program:outpost-octane]')
        ->and($config)->not->toContain('[program:php-fpm]');
});

it('runs each detected service', function (string $service, string $needle) {
    expect((new Supervisord)->generate(fakeManifest(services: [$service])))->toContain($needle);
})->with([
    'mysql' => ['mysql', '[program:mysql]'],
    'pgsql' => ['pgsql', '[program:pgsql]'],
    'redis' => ['redis', '[program:redis]'],
    'mailpit' => ['mailpit', '[program:mailpit]'],
]);

it('runs postgres as the postgres user', function () {
    $config = (new Supervisord)->generate(fakeManifest(services: ['pgsql']));

    expect($config)->toContain('user=postgres');
});

it('starts services before php-fpm and nginx last', function () {
    $config = (new Supervisord)->generate(fakeManifest(services: ['mysql', 'redis', 'mailpit']));

    preg_match_all('/priority=(\d+)/', $config, $matches);

    expect($matches[1])->toBe(['10', '15', '20', '25', '30']);
});

it('sends every program log to the container output', function () {
    $config = (new Supervisord)->generate(fakeManifest(services: ['mysql']));

    expect(substr_count($config, 'stdout_logfile=/dev/stdout'))->toBe(3)
        ->and(substr_count($config, 'stderr_logfile=/dev/stderr'))->toBe(3)
        ->and($config)->not->toContain('logfile_maxbytes=10');
});

it('runs configured application processes after provisioning', function () {
    $config = (new Supervisord)->generate(fakeManifest(processes: ['queue']), [
        'queue' => ['php8.4', 'artisan', 'queue:work', '--queue=high priority'],
    ]);

    expect($config)->toContain('[program:outpost-queue]')
        ->and($config)->toContain('command=/usr/local/bin/outpost-wait "php8.4" "artisan" "queue:work" "--queue=high priority"')
        ->and($config)->toContain('directory=/app')
        ->and($config)->toContain('user=www-data')
        ->and($config)->toContain('stopasgroup=true')
        ->and($config)->toContain('killasgroup=true');
});

it('escapes supervisor command arguments without invoking a shell', function () {
    $config = (new Supervisord)->generate(fakeManifest(processes: ['worker']), [
        'worker' => ['binary', 'a "quoted" value', 'a\\path', '100%'],
    ]);

    expect($config)->toContain('command=/usr/local/bin/outpost-wait "binary" "a \\"quoted\\" value" "a\\\\path" "100%%"');
});
