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
