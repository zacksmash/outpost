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
        ->and($detection->server)->toBe('fpm')
        ->and($detection->octaneServer)->toBeNull()
        ->and($detection->frontend)->toBe('build')
        ->and($detection->deferred)->toBe([]);
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

it('detects redis through the cache store', function () {
    expect(detectorWith(['cache.default' => 'redis'])->services)->toBe(['redis']);
});

it('detects redis through the session driver', function () {
    expect(detectorWith(['session.driver' => 'redis'])->services)->toBe(['redis']);
});

it('detects redis through the queue connection', function () {
    expect(detectorWith(['queue.default' => 'redis'])->services)->toBe(['redis']);
});

it('detects redis through the broadcast connection', function () {
    expect(detectorWith([
        'broadcasting.default' => 'redis',
        'broadcasting.connections.redis.driver' => 'redis',
    ])->services)->toBe(['redis']);
});

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

it('runs detected octane applications and only defers horizon', function () {
    $detection = detectorWith([
        'horizon' => ['use' => 'default'],
        'octane' => ['server' => 'swoole'],
    ]);

    expect($detection->server)->toBe('octane')
        ->and($detection->octaneServer)->toBe('swoole')
        ->and($detection->deferred)->toBe(['horizon']);
});

it('uses the application octane server automatically', function (string $server) {
    $detection = detectorWith([
        'octane' => ['server' => $server],
    ]);

    expect($detection->server)->toBe('octane')
        ->and($detection->octaneServer)->toBe($server);
})->with(['swoole', 'frankenphp']);

it('uses roadrunner when its php worker package is locked', function () {
    $root = sys_get_temp_dir().'/outpost-roadrunner-'.Str::random(10);
    File::ensureDirectoryExists($root);
    File::put($root.'/composer.lock', json_encode([
        'packages' => [
            ['name' => 'spiral/roadrunner-http'],
        ],
        'packages-dev' => [],
    ], JSON_THROW_ON_ERROR));

    try {
        $detection = detectorWith(['octane' => ['server' => 'roadrunner']], $root);

        expect($detection->octaneServer)->toBe('roadrunner');
    } finally {
        File::deleteDirectory($root);
    }
});

it('refuses roadrunner without its php worker package', function () {
    detectorWith(['octane' => ['server' => 'roadrunner']]);
})->throws(RuntimeException::class, 'spiral/roadrunner-http');

it('refuses frankenphp when the application cannot run its embedded php version', function () {
    $root = sys_get_temp_dir().'/outpost-frankenphp-'.Str::random(10);
    File::ensureDirectoryExists($root);
    File::put($root.'/composer.json', json_encode([
        'require' => ['php' => '~8.4.0'],
    ], JSON_THROW_ON_ERROR));

    try {
        detectorWith(['octane' => ['server' => 'frankenphp']], $root);
    } finally {
        File::deleteDirectory($root);
    }
})->throws(RuntimeException::class, 'FrankenPHP uses PHP 8.5');

it('can override the application octane server', function () {
    $detection = detectorWith([
        'octane' => ['server' => 'swoole'],
        'outpost.octane.server' => 'frankenphp',
    ]);

    expect($detection->server)->toBe('octane')
        ->and($detection->octaneServer)->toBe('frankenphp');
});

it('preserves swoole as the fallback for octane configs without a server', function () {
    $detection = detectorWith(['octane' => []]);

    expect($detection->server)->toBe('octane')
        ->and($detection->octaneServer)->toBe('swoole');
});

it('can force php fpm for an octane application', function () {
    $detection = detectorWith([
        'octane' => ['server' => 'swoole'],
        'outpost.server' => 'fpm',
    ]);

    expect($detection->server)->toBe('fpm')
        ->and($detection->octaneServer)->toBeNull()
        ->and($detection->deferred)->toBe(['octane']);
});

it('refuses an unsupported octane server', function (array $config) {
    detectorWith([
        'octane' => ['server' => 'swoole'],
        ...$config,
    ]);
})->with([
    'application config' => [['octane.server' => 'hyper']],
    'outpost override' => [['outpost.octane.server' => 'hyper']],
])->throws(RuntimeException::class, 'Octane server');

it('refuses an explicit octane server when octane is not installed', function () {
    detectorWith(['outpost.server' => 'octane']);
})->throws(RuntimeException::class, 'does not expose an Octane configuration');

it('refuses an unsupported web server', function () {
    detectorWith(['outpost.server' => 'apache']);
})->throws(RuntimeException::class, 'outpost.server');

it('supports build, vite, and disabled frontend modes', function (string $frontend) {
    $root = sys_get_temp_dir().'/outpost-frontend-'.Str::random(10);
    File::ensureDirectoryExists($root);
    File::put($root.'/package.json', json_encode([
        'scripts' => ['dev' => 'vite', 'build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));

    try {
        expect(detectorWith(['outpost.frontend' => $frontend], $root)->frontend)->toBe($frontend);
    } finally {
        File::deleteDirectory($root);
    }
})->with(['build', 'vite', 'none']);

it('refuses vite mode without a dev script', function () {
    $root = sys_get_temp_dir().'/outpost-frontend-'.Str::random(10);
    File::ensureDirectoryExists($root);
    File::put($root.'/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ], JSON_THROW_ON_ERROR));

    try {
        detectorWith(['outpost.frontend' => 'vite'], $root);
    } finally {
        File::deleteDirectory($root);
    }
})->throws(RuntimeException::class, 'requires a package.json dev script');

it('refuses an unsupported frontend mode', function () {
    detectorWith(['outpost.frontend' => 'webpack']);
})->throws(RuntimeException::class, 'outpost.frontend');

it('does not defer horizon when a horizon process is configured', function () {
    $detection = detectorWith([
        'horizon' => ['use' => 'default'],
        'outpost.processes' => [
            'horizon' => ['@php', 'artisan', 'horizon'],
        ],
    ]);

    expect($detection->deferred)->toBe([]);
});

it('defers external scout drivers', function () {
    expect(detectorWith(['scout.driver' => 'meilisearch'])->deferred)->toBe(['meilisearch'])
        ->and(detectorWith(['scout.driver' => 'typesense'])->deferred)->toBe(['typesense'])
        ->and(detectorWith(['scout.driver' => 'algolia'])->deferred)->toBe(['algolia']);
});

it('does not defer in-database scout drivers', function () {
    expect(detectorWith(['scout.driver' => 'database'])->deferred)->toBe([]);
});

it('defers unsupported database drivers instead of running them', function () {
    $detection = detectorWith([
        'database.default' => 'sqlsrv',
        'database.connections.sqlsrv.driver' => 'sqlsrv',
    ]);

    expect($detection->services)->toBe([])
        ->and($detection->database)->toBe('sqlsrv')
        ->and($detection->deferred)->toBe(['sqlsrv']);
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
