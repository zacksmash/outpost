<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\File;
use RuntimeException;
use UnexpectedValueException;

class Detector
{
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
        $services = $this->services($database);

        return new Detection(
            services: $services,
            database: is_array($this->config->get('outpost.services'))
                ? DatabaseServices::reconcile($database, $services)
                : $database,
            php: $this->php(),
            frontend: $this->frontend(),
        );
    }

    /**
     * The services Outpost knows how to run inside an instance.
     */
    protected const array SUPPORTED_SERVICES = ['mysql', 'pgsql', 'redis', 'mailpit'];

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
                if (! is_string($service) || ! in_array($service, self::SUPPORTED_SERVICES, true)) {
                    throw new RuntimeException(sprintf(
                        'The [outpost.services] values may only contain: %s.',
                        implode(', ', self::SUPPORTED_SERVICES),
                    ));
                }

                $services[] = $service;
            }

            return $services;
        }

        $services = [];

        if (($databaseService = DatabaseServices::forConnection($database)) !== null) {
            $services[] = $databaseService;
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
     * Determine how frontend assets should be prepared.
     */
    protected function frontend(): string
    {
        $frontend = $this->config->get('outpost.frontend', 'build');

        if (! is_string($frontend) || ! in_array($frontend, ['build', 'none'], true)) {
            throw new RuntimeException('The [outpost.frontend] value must be build or none.');
        }

        return $frontend;
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
            if (! is_string($version) || preg_match('/^\d+\.\d+$/D', $version) !== 1) {
                throw new RuntimeException('The [outpost.php] versions must look like "8.4".');
            }

            $versions[] = $version;
        }

        if ($versions === []) {
            throw new RuntimeException('No PHP versions are configured for Outpost instances.');
        }

        usort($versions, fn (string $a, string $b): int => version_compare($a, $b));

        $versions = array_reverse($versions);

        if (($constraint = $this->phpConstraint()) === null) {
            return $versions[0];
        }

        $parser = new VersionParser;

        try {
            $required = $parser->parseConstraints($constraint);
        } catch (UnexpectedValueException) {
            throw new RuntimeException(
                "The application's PHP constraint [{$constraint}] could not be parsed.",
            );
        }

        foreach ($versions as $version) {
            if (Intervals::haveIntersections($required, $parser->parseConstraints($version.'.*'))) {
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
