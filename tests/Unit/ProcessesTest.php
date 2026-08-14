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
        'outpost.processes' => [],
        'outpost.vite.port' => 5173,
        'outpost.vite.hot_file' => 'public/hot',
    ]);

    expect((new Processes(app('config')))->commands(
        php: '8.5',
        server: 'octane',
        frontend: 'vite',
        url: 'http://billing-app.outpost',
    ))->toBe([
        'octane' => [
            'env', 'CHOKIDAR_USEPOLLING=true', 'php8.5', 'artisan', 'octane:start',
            '--server=swoole', '--host=127.0.0.1', '--port=8000', '--watch',
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

it('rejects configured processes that collide with managed processes', function () {
    config(['outpost.processes' => [
        'octane' => ['@php', 'artisan', 'something-else'],
    ]]);

    (new Processes(app('config')))->commands('8.4', server: 'octane');
})->throws(RuntimeException::class, 'reserved');

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
