<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Composer\Semver\Semver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\File;
use RuntimeException;

class Detector
{
    /**
     * The database drivers instances know how to run.
     *
     * @var list<string>
     */
    protected const array DATABASE_DRIVERS = ['sqlite', 'mysql', 'mariadb', 'pgsql'];

    /**
     * Create a new detector instance.
     */
    public function __construct(
        protected readonly Repository $config,
        protected readonly string $basePath,
    ) {}

    /**
     * Inspect the application and determine what its instances need.
     */
    public function detect(): Detection
    {
        $database = $this->database();

        return new Detection(
            services: $this->services($database),
            deferred: $this->deferred($database),
            database: $database,
            php: $this->php(),
        );
    }

    /**
     * Determine which services an instance of the application should run.
     *
     * @return list<string>
     */
    protected function services(?string $database): array
    {
        if (is_array($override = $this->config->get('outpost.services'))) {
            $services = [];

            foreach ($override as $service) {
                if (is_string($service)) {
                    $services[] = $service;
                }
            }

            return $services;
        }

        $services = [];

        if (in_array($database, ['mysql', 'mariadb'], true)) {
            $services[] = 'mysql';
        }

        if ($database === 'pgsql') {
            $services[] = 'pgsql';
        }

        if ($this->usesRedis()) {
            $services[] = 'redis';
        }

        if ($this->resolve('mail.default', 'mail.mailers', 'transport') === 'smtp') {
            $services[] = 'mailpit';
        }

        return $services;
    }

    /**
     * Determine which detected capabilities an instance will not run.
     *
     * @return list<string>
     */
    protected function deferred(?string $database): array
    {
        $deferred = [];

        if ($this->config->has('horizon')) {
            $deferred[] = 'horizon';
        }

        if ($this->config->has('octane')) {
            $deferred[] = 'octane';
        }

        $scout = $this->config->get('scout.driver');

        if (in_array($scout, ['meilisearch', 'typesense'], true)) {
            $deferred[] = $scout;
        }

        if ($database !== null && ! in_array($database, self::DATABASE_DRIVERS, true)) {
            $deferred[] = $database;
        }

        return $deferred;
    }

    /**
     * Determine the application's database driver.
     */
    protected function database(): ?string
    {
        return $this->resolve('database.default', 'database.connections', 'driver');
    }

    /**
     * Determine if the application uses Redis anywhere that matters.
     */
    protected function usesRedis(): bool
    {
        return $this->resolve('cache.default', 'cache.stores', 'driver') === 'redis'
            || $this->config->get('session.driver') === 'redis'
            || $this->resolve('queue.default', 'queue.connections', 'driver') === 'redis'
            || $this->resolve('broadcasting.default', 'broadcasting.connections', 'driver') === 'redis';
    }

    /**
     * Determine the PHP version an instance of the application should run.
     */
    protected function php(): string
    {
        $versions = [];

        foreach ((array) $this->config->get('outpost.php', []) as $version) {
            if (is_string($version)) {
                $versions[] = $version;
            }
        }

        if ($versions === []) {
            throw new RuntimeException('No PHP versions are configured for Outpost instances.');
        }

        usort($versions, fn (string $a, string $b): int => version_compare($a, $b));

        $versions = array_reverse($versions);

        if (($constraint = $this->phpConstraint()) === null) {
            return $versions[0];
        }

        foreach ($versions as $version) {
            if (Semver::satisfies($version.'.0', $constraint)) {
                return $version;
            }
        }

        throw new RuntimeException(sprintf(
            "None of the configured PHP versions [%s] satisfy the application's constraint [%s].",
            implode(', ', $versions),
            $constraint,
        ));
    }

    /**
     * Read the application's PHP constraint from its composer.json file.
     */
    protected function phpConstraint(): ?string
    {
        $path = $this->basePath.'/composer.json';

        if (! File::exists($path)) {
            return null;
        }

        $constraint = data_get(File::json($path), 'require.php');

        return is_string($constraint) ? $constraint : null;
    }

    /**
     * Resolve a driver-style value from the application's configuration.
     */
    protected function resolve(string $default, string $list, string $field): ?string
    {
        $name = $this->config->get($default);

        if (! is_string($name) || $name === '') {
            return null;
        }

        $value = $this->config->get("{$list}.{$name}.{$field}");

        return is_string($value) ? $value : null;
    }
}
