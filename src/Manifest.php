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
     */
    public function __construct(
        public readonly string $name,
        public readonly string $container,
        public readonly string $url,
        public readonly string $branch,
        public readonly string $php,
        public readonly array $services,
        public readonly array $deferred,
        public readonly ?string $database,
        public readonly CarbonImmutable $createdAt,
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
            services: static::stringList($data, 'services'),
            deferred: static::stringList($data, 'deferred'),
            database: $data['database'] ?? null,
            createdAt: $createdAt,
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
            'services' => $this->services,
            'deferred' => $this->deferred,
            'database' => $this->database,
            'created_at' => $this->createdAt->toIso8601String(),
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
