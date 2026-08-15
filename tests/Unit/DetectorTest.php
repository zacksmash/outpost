<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Zacksmash\Outpost\Detection;
use Zacksmash\Outpost\Detector;

function detectorWith(array $config = [], ?string $basePath = null): Detection
{
    config([
        'database.default' => 'sqlite',
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'broadcasting.default' => 'null',
        'mail.default' => 'log',
        ...$config,
    ]);

    return (new Detector(app('config'), $basePath ?? base_path()))->detect();
}

it('needs no services for a sqlite application', function () {
    $detection = detectorWith();

    expect($detection->services)->toBe([])
        ->and($detection->database)->toBe('sqlite')
        ->and($detection->frontend)->toBe('build');
});

it('detects mysql', function () {
    $detection = detectorWith(['database.default' => 'mysql']);

    expect($detection->services)->toBe(['mysql'])
        ->and($detection->database)->toBe('mysql');
});

it('runs mariadb applications on the mysql service', function () {
    $detection = detectorWith([
        'database.default' => 'mariadb',
        'database.connections.mariadb.driver' => 'mariadb',
    ]);

    expect($detection->services)->toBe(['mysql'])
        ->and($detection->database)->toBe('mariadb');
});

it('detects postgres', function () {
    $detection = detectorWith(['database.default' => 'pgsql']);

    expect($detection->services)->toBe(['pgsql'])
        ->and($detection->database)->toBe('pgsql');
});

it('detects redis through laravel services', function (array $configuration) {
    expect(detectorWith($configuration)->services)->toBe(['redis']);
})->with([
    'cache' => [['cache.default' => 'redis']],
    'session' => [['session.driver' => 'redis']],
    'queue' => [['queue.default' => 'redis']],
    'broadcasting' => [[
        'broadcasting.default' => 'redis',
        'broadcasting.connections.redis.driver' => 'redis',
    ]],
]);

it('detects mailpit through the smtp transport', function () {
    expect(detectorWith(['mail.default' => 'smtp'])->services)->toBe(['mailpit']);
});

it('detects a full stack in a stable order', function () {
    $detection = detectorWith([
        'database.default' => 'mysql',
        'queue.default' => 'redis',
        'mail.default' => 'smtp',
    ]);

    expect($detection->services)->toBe(['mysql', 'redis', 'mailpit']);
});

it('lets the outpost config override detection entirely', function () {
    $detection = detectorWith([
        'outpost.services' => ['redis'],
        'database.default' => 'mysql',
    ]);

    expect($detection->services)->toBe(['redis'])
        ->and($detection->database)->toBe('mysql');
});

it('supports build and disabled frontend modes', function (string $frontend) {
    expect(detectorWith(['outpost.frontend' => $frontend])->frontend)->toBe($frontend);
})->with(['build', 'none']);

it('refuses unsupported frontend modes', function (string $frontend) {
    detectorWith(['outpost.frontend' => $frontend]);
})->with(['vite', 'webpack'])->throws(RuntimeException::class, 'outpost.frontend');

it('does not provision unsupported database drivers', function () {
    $detection = detectorWith([
        'database.default' => 'sqlsrv',
        'database.connections.sqlsrv.driver' => 'sqlsrv',
    ]);

    expect($detection->services)->toBe([])
        ->and($detection->database)->toBe('sqlsrv');
});

describe('php version selection', function () {
    beforeEach(function () {
        $this->composerPath = sys_get_temp_dir().'/outpost-detector-'.Str::random(10);

        File::ensureDirectoryExists($this->composerPath);
    });

    afterEach(function () {
        File::deleteDirectory($this->composerPath);
    });

    it('selects the highest version satisfying the constraint', function (string $constraint, string $expected) {
        File::put(
            $this->composerPath.'/composer.json',
            json_encode(['require' => ['php' => $constraint]], JSON_THROW_ON_ERROR),
        );

        expect(detectorWith([], $this->composerPath)->php)->toBe($expected);
    })->with([
        'caret' => ['^8.2', '8.5'],
        'tilde patch' => ['~8.4.0', '8.4'],
        'exact minor' => ['8.4.*', '8.4'],
        'patch floor' => ['~8.4.5', '8.4'],
        'patch minimum' => ['>=8.4.2', '8.5'],
    ]);

    it('refuses a constraint it cannot parse', function () {
        File::put(
            $this->composerPath.'/composer.json',
            json_encode(['require' => ['php' => 'not^a@constraint']], JSON_THROW_ON_ERROR),
        );

        detectorWith([], $this->composerPath);
    })->throws(RuntimeException::class, 'could not be parsed');

    it('selects the highest configured version without a constraint', function () {
        expect(detectorWith([], $this->composerPath)->php)->toBe('8.5');
    });

    it('sorts the configured versions before picking', function () {
        expect(detectorWith(['outpost.php' => ['8.5', '8.4']], $this->composerPath)->php)->toBe('8.5');
    });

    it('refuses a constraint no configured version satisfies', function () {
        File::put(
            $this->composerPath.'/composer.json',
            json_encode(['require' => ['php' => '^9.0']], JSON_THROW_ON_ERROR),
        );

        detectorWith([], $this->composerPath);
    })->throws(RuntimeException::class, "satisfy the application's constraint [^9.0]");

    it('refuses an empty version list', function () {
        detectorWith(['outpost.php' => []], $this->composerPath);
    })->throws(RuntimeException::class, 'No PHP versions are configured');
});
