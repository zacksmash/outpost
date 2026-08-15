<?php

declare(strict_types=1);

use Zacksmash\Outpost\Processes;

it('resolves configured process commands and pins the php placeholder', function () {
    config(['outpost.processes' => [
        'queue' => ['@php', 'artisan', 'queue:work', '--queue=high priority'],
        'vite' => ['npm', 'run', 'dev'],
    ]]);

    expect((new Processes(app('config')))->commands('8.4'))->toBe([
        'queue' => ['php8.4', 'artisan', 'queue:work', '--queue=high priority'],
        'vite' => ['npm', 'run', 'dev'],
    ]);
});

it('allows no configured processes', function () {
    config(['outpost.processes' => []]);

    expect((new Processes(app('config')))->commands('8.4'))->toBe([]);
});

it('adds the octane and vite development processes', function () {
    config([
        'octane.watch' => ['app', 'config/**/*.php', 'routes'],
        'outpost.processes' => [],
        'outpost.vite.port' => 5173,
        'outpost.vite.hot_file' => 'public/hot',
    ]);

    expect((new Processes(app('config')))->commands(
        php: '8.5',
        server: 'octane',
        octaneServer: 'swoole',
        frontend: 'vite',
        url: 'http://billing-app.outpost',
    ))->toBe([
        'octane' => [
            '/bin/bash', '/etc/outpost/octane-watch', 'php8.5',
            '["/app/app","/app/config/**/*.php","/app/routes"]',
            'php8.5', 'artisan', 'octane:start',
            '--server=swoole', '--host=127.0.0.1', '--port=8000',
        ],
        'vite' => [
            '/usr/local/bin/outpost-vite',
            'http://billing-app.outpost:5173',
            'public/hot',
            'env', 'CHOKIDAR_USEPOLLING=true',
            '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=billing-app.outpost',
            'npm', 'run', 'dev', '--',
            '--host', '127.0.0.1', '--port', '24678', '--strictPort',
        ],
    ]);
});

it('builds the polling watcher command for each octane server', function (string $server, array $options) {
    config([
        'octane.watch' => ['app', 'routes/**/*.php'],
        'outpost.processes' => [],
    ]);

    expect((new Processes(app('config')))->commands(
        php: '8.5',
        server: 'octane',
        octaneServer: $server,
    )['octane'])->toBe([
        '/bin/bash', '/etc/outpost/octane-watch', 'php8.5',
        '["/app/app","/app/routes/**/*.php"]',
        'php8.5', 'artisan', 'octane:start',
        "--server={$server}", '--host=127.0.0.1', '--port=8000', ...$options,
    ]);
})->with([
    'Swoole' => ['swoole', []],
    'RoadRunner' => ['roadrunner', ['--rpc-port=6001']],
    'FrankenPHP' => ['frankenphp', ['--admin-port=2019']],
]);

it('refuses an unsupported managed octane server', function () {
    config(['outpost.processes' => []]);

    (new Processes(app('config')))->commands(
        php: '8.5',
        server: 'octane',
        octaneServer: 'hyper',
    );
})->throws(RuntimeException::class, 'Octane server');

it('rejects configured processes that collide with managed processes', function () {
    config([
        'octane.watch' => ['app'],
        'outpost.processes' => [
            'octane' => ['@php', 'artisan', 'something-else'],
        ],
    ]);

    (new Processes(app('config')))->commands('8.4', server: 'octane');
})->throws(RuntimeException::class, 'reserved');

it('rejects missing or unsafe octane watch paths', function (mixed $watch) {
    config([
        'octane.watch' => $watch,
        'outpost.processes' => [],
    ]);

    (new Processes(app('config')))->commands('8.5', server: 'octane');
})->with([
    'missing paths' => [[]],
    'not a list' => [['app' => true]],
    'absolute path' => [['/Users/example/app']],
    'traversal' => [['../secrets']],
    'empty path' => [['']],
])->throws(RuntimeException::class, 'octane.watch');

it('validates the vite port and hot file', function (array $vite) {
    config([
        'outpost.processes' => [],
        'outpost.vite' => $vite,
    ]);

    (new Processes(app('config')))->commands(
        php: '8.4',
        frontend: 'vite',
        url: 'http://billing-app.outpost',
    );
})->with([
    'bad port' => [['port' => 70000, 'hot_file' => 'public/hot']],
    'reserved internal port' => [['port' => 24678, 'hot_file' => 'public/hot']],
    'absolute hot file' => [['port' => 5173, 'hot_file' => '/tmp/hot']],
    'traversing hot file' => [['port' => 5173, 'hot_file' => '../hot']],
])->throws(RuntimeException::class);

it('rejects a process collection that is not an array', function () {
    config(['outpost.processes' => 'artisan queue:work']);

    (new Processes(app('config')))->commands('8.4');
})->throws(RuntimeException::class, 'The [outpost.processes] value must be an associative array.');

it('rejects unsafe process names', function (string $name) {
    config(['outpost.processes' => [
        $name => ['@php', 'artisan', 'queue:work'],
    ]]);

    (new Processes(app('config')))->commands('8.4');
})->with([
    'spaces' => ['queue worker'],
    'section syntax' => ['queue]'],
    'leading dash' => ['-queue'],
    'uppercase' => ['Queue'],
])->throws(RuntimeException::class);

it('rejects malformed process commands', function (mixed $command) {
    config(['outpost.processes' => ['queue' => $command]]);

    (new Processes(app('config')))->commands('8.4');
})->with([
    'string command' => ['artisan queue:work'],
    'empty command' => [[]],
    'associative command' => [['binary' => 'php']],
    'non-string token' => [['@php', 'artisan', 42]],
    'empty token' => [['@php', '']],
    'newline token' => [['@php', "artisan\nmalicious"]],
    'supervisor comment token' => [['binary', 'one;two']],
])->throws(RuntimeException::class);
