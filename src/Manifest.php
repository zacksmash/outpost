<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * @implements Arrayable<string, mixed>
 */
class Manifest implements Arrayable
{
    /**
     * Create a new manifest instance.
     *
     * @param  list<string>  $services
     * @param  list<string>  $deferred
     * @param  list<string>  $processes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $container,
        public readonly string $url,
        public readonly string $branch,
        public readonly string $php,
        public readonly string $server,
        public readonly string $frontend,
        public readonly bool $exposeServices,
        public readonly array $services,
        public readonly array $deferred,
        public readonly array $processes,
        public readonly ?string $database,
        public readonly CarbonImmutable $createdAt,
        public readonly ?int $cpus = null,
        public readonly ?string $memory = null,
    ) {}

    /**
     * Create a manifest from its array representation.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['name', 'container', 'url', 'branch', 'php'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                throw new InvalidArgumentException("The manifest [{$key}] value must be a string.");
            }
        }

        if (isset($data['database']) && ! is_string($data['database'])) {
            throw new InvalidArgumentException('The manifest [database] value must be a string or null.');
        }

        if (array_key_exists('server', $data)
            && (! is_string($data['server']) || ! in_array($data['server'], ['fpm', 'octane'], true))) {
            throw new InvalidArgumentException('The manifest [server] value must be fpm or octane.');
        }

        if (array_key_exists('frontend', $data)
            && (! is_string($data['frontend']) || ! in_array($data['frontend'], ['build', 'vite', 'none'], true))) {
            throw new InvalidArgumentException('The manifest [frontend] value must be build, vite, or none.');
        }

        if (array_key_exists('expose_services', $data) && ! is_bool($data['expose_services'])) {
            throw new InvalidArgumentException('The manifest [expose_services] value must be a boolean.');
        }

        if (array_key_exists('cpus', $data)
            && $data['cpus'] !== null
            && (! is_int($data['cpus']) || $data['cpus'] < 1)) {
            throw new InvalidArgumentException('The manifest [cpus] value must be a positive integer or null.');
        }

        if (array_key_exists('memory', $data)
            && $data['memory'] !== null
            && (! is_string($data['memory']) || $data['memory'] === '')) {
            throw new InvalidArgumentException('The manifest [memory] value must be a non-empty string or null.');
        }

        if (! isset($data['created_at']) || ! is_string($data['created_at'])) {
            throw new InvalidArgumentException('The manifest [created_at] value must be a string.');
        }

        try {
            if (trim($data['created_at']) === '') {
                throw new InvalidFormatException('Empty date.');
            }

            $createdAt = CarbonImmutable::parse($data['created_at']);
        } catch (InvalidFormatException) {
            throw new InvalidArgumentException('The manifest [created_at] value is not a valid date.');
        }

        return new self(
            name: $data['name'],
            container: $data['container'],
            url: $data['url'],
            branch: $data['branch'],
            php: $data['php'],
            server: $data['server'] ?? 'fpm',
            frontend: $data['frontend'] ?? 'build',
            exposeServices: $data['expose_services'] ?? false,
            services: static::stringList($data, 'services'),
            deferred: static::stringList($data, 'deferred'),
            processes: array_key_exists('processes', $data)
                ? static::stringList($data, 'processes')
                : [],
            database: $data['database'] ?? null,
            createdAt: $createdAt,
            cpus: $data['cpus'] ?? null,
            memory: $data['memory'] ?? null,
        );
    }

    /**
     * Determine if the instance runs the given service.
     */
    public function uses(string $service): bool
    {
        return in_array($service, $this->services, true);
    }

    /**
     * Determine whether the instance serves trusted HTTPS.
     */
    public function secure(): bool
    {
        return str_starts_with($this->url, 'https://');
    }

    /**
     * Get the array representation of the manifest.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'container' => $this->container,
            'url' => $this->url,
            'branch' => $this->branch,
            'php' => $this->php,
            'server' => $this->server,
            'frontend' => $this->frontend,
            'expose_services' => $this->exposeServices,
            'services' => $this->services,
            'deferred' => $this->deferred,
            'processes' => $this->processes,
            'database' => $this->database,
            'created_at' => $this->createdAt->toIso8601String(),
            'cpus' => $this->cpus,
            'memory' => $this->memory,
        ];
    }

    /**
     * Extract a list of strings from the given manifest data.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected static function stringList(array $data, string $key): array
    {
        $values = $data[$key] ?? null;

        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException("The manifest [{$key}] value must be a list.");
        }

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("The manifest [{$key}] value must only contain strings.");
            }
        }

        return $values;
    }
}
