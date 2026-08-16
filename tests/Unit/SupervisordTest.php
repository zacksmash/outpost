<?php

declare(strict_types=1);

use Zacksmash\Outpost\Supervisord;

beforeEach(function () {
    $this->supervisord = new Supervisord(app('config'));
});

it('always runs php-fpm and nginx', function () {
    $config = $this->supervisord->generate(fakeManifest(services: []));

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
    expect($this->supervisord->generate(fakeManifest(php: '8.5', services: [])))
        ->toContain('command=/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /etc/php/8.5/fpm/php-fpm.conf');
});

it('runs each detected service', function (string $service, string $needle) {
    expect($this->supervisord->generate(fakeManifest(services: [$service])))->toContain($needle);
})->with([
    'mysql' => ['mysql', '[program:mysql]'],
    'pgsql' => ['pgsql', '[program:pgsql]'],
    'redis' => ['redis', '[program:redis]'],
    'mailpit' => ['mailpit', '[program:mailpit]'],
]);

it('runs postgres as the postgres user', function () {
    $config = $this->supervisord->generate(fakeManifest(services: ['pgsql']));

    expect($config)->toContain('user=postgres');
});

it('keeps redis persistence out of the application worktree', function () {
    $config = $this->supervisord->generate(fakeManifest(services: ['redis']));

    expect($config)
        ->toContain('--dir /var/lib/redis')
        ->toContain("[program:redis]\ncommand=")
        ->toContain("priority=15\nuser=redis");
});

it('keeps mailpit private behind the nginx service endpoint', function () {
    $config = $this->supervisord->generate(fakeManifest(services: ['mailpit']));

    expect($config)->toContain('--listen 127.0.0.1:8026');
});

it('starts services before php-fpm and nginx last', function () {
    $config = $this->supervisord->generate(fakeManifest(services: ['mysql', 'redis', 'mailpit']));

    preg_match_all('/priority=(\d+)/', $config, $matches);

    expect($matches[1])->toBe(['10', '15', '20', '25', '30']);
});

it('sends every program log to the container output', function () {
    $config = $this->supervisord->generate(fakeManifest(services: ['mysql']));

    expect(substr_count($config, 'stdout_logfile=/dev/stdout'))->toBe(3)
        ->and(substr_count($config, 'stderr_logfile=/dev/stderr'))->toBe(3)
        ->and($config)->not->toContain('logfile_maxbytes=10');
});

it('runs configured application processes after provisioning', function () {
    $config = $this->supervisord->generate(fakeManifest(processes: ['queue']), [
        'queue' => ['php8.4', 'artisan', 'queue:work', '--queue=high priority'],
    ]);

    expect($config)->toContain('[program:outpost-queue]')
        ->and($config)->toContain('command=/usr/local/bin/outpost-wait "php8.4" "artisan" "queue:work" "--queue=high priority"')
        ->and($config)->toContain('directory=/app')
        ->and($config)->toContain('user=outpost')
        ->and($config)->toContain('stopasgroup=true')
        ->and($config)->toContain('killasgroup=true');
});

it('escapes supervisor command arguments without invoking a shell', function () {
    $config = $this->supervisord->generate(fakeManifest(processes: ['worker']), [
        'worker' => ['binary', 'a "quoted" value', 'a\\path', '100%'],
    ]);

    expect($config)->toContain('command=/usr/local/bin/outpost-wait "binary" "a \\"quoted\\" value" "a\\\\path" "100%%"');
});

it('exposes stateful services with sandbox authentication', function () {
    $config = $this->supervisord->generate(fakeManifest(
        services: ['mysql', 'pgsql', 'redis', 'mailpit'],
        exposeServices: true,
    ));

    expect($config)->toContain('mysqld --user=mysql --bind-address=0.0.0.0')
        ->and($config)->toContain('listen_addresses=*')
        ->and($config)->toContain('redis-server --bind 0.0.0.0 --protected-mode yes --requirepass password')
        ->and($config)->toContain('mailpit --smtp 0.0.0.0:1025 --listen 127.0.0.1:8026');
});

it('keeps services on loopback when direct access is disabled', function () {
    $config = $this->supervisord->generate(fakeManifest(
        services: ['mysql', 'pgsql', 'redis', 'mailpit'],
        exposeServices: false,
    ));

    expect($config)->toContain('mysqld --user=mysql --bind-address=127.0.0.1')
        ->and($config)->not->toContain('listen_addresses=*')
        ->and($config)->toContain('redis-server --bind 127.0.0.1')
        ->and($config)->toContain('mailpit --smtp 127.0.0.1:1025 --listen 127.0.0.1:8026');
});
