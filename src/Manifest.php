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
     * @param  list<string>  $processes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $container,
        public readonly string $url,
        public readonly string $branch,
        public readonly string $php,
        public readonly string $frontend,
        public readonly bool $exposeServices,
        public readonly array $services,
        public readonly array $processes,
        public readonly ?string $database,
        public readonly CarbonImmutable $createdAt,
        public readonly ?int $cpus = null,
        public readonly ?string $memory = null,
        public readonly string $status = 'ready',
        public readonly ?string $image = null,
        public readonly ?string $imageDigest = null,
        public readonly string $runtime = Runtime::DRIVER,
    ) {}

    /**
     * Create a manifest from its array representation.
     *
     * Legacy Octane and Vite fields are deliberately ignored. Rebuilt
     * instances use Outpost's standard PHP-FPM and asset-build runtime.
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

        // The PHP version and URL are interpolated into generated nginx,
        // supervisord, and .env files on every rebuild, so a hand-edited
        // manifest must not be able to smuggle arbitrary content there.
        if (preg_match('/^\d+\.\d+$/D', $data['php']) !== 1) {
            throw new InvalidArgumentException('The manifest [php] value must be a version like "8.4".');
        }

        if (preg_match('#^https?://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$#Di', $data['url']) !== 1) {
            throw new InvalidArgumentException('The manifest [url] value must be an http or https URL with only a hostname.');
        }

        if (isset($data['database']) && ! is_string($data['database'])) {
            throw new InvalidArgumentException('The manifest [database] value must be a string or null.');
        }

        $frontend = $data['frontend'] ?? 'build';

        if (! is_string($frontend) || ! in_array($frontend, ['build', 'vite', 'none'], true)) {
            throw new InvalidArgumentException('The manifest [frontend] value must be build or none.');
        }

        if ($frontend === 'vite') {
            $frontend = 'build';
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

        if (array_key_exists('status', $data)
            && (! is_string($data['status'])
                || ! in_array($data['status'], ['provisioning', 'ready', 'failed'], true))) {
            throw new InvalidArgumentException('The manifest [status] value must be provisioning, ready, or failed.');
        }

        $image = $data['image'] ?? null;
        $imageDigest = $data['image_digest'] ?? null;
        $runtime = $data['runtime'] ?? Runtime::DRIVER;

        if (! is_string($runtime)
            || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D', $runtime) !== 1) {
            throw new InvalidArgumentException('The manifest [runtime] value must be a lowercase driver identifier.');
        }

        if (($image === null) !== ($imageDigest === null)) {
            throw new InvalidArgumentException('The manifest [image] and [image_digest] values must either both be present or both be null.');
        }

        if ($image !== null
            && (! is_string($image) || $image === '' || trim($image) !== $image || preg_match('/\s/', $image) === 1)) {
            throw new InvalidArgumentException('The manifest [image] value must be a non-empty OCI image reference or null.');
        }

        if ($imageDigest !== null
            && (! is_string($imageDigest) || preg_match('/^[a-z0-9]+:[a-f0-9]{32,}$/D', $imageDigest) !== 1)) {
            throw new InvalidArgumentException('The manifest [image_digest] value must be an OCI digest or null.');
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
            frontend: $frontend,
            exposeServices: $data['expose_services'] ?? false,
            services: static::stringList($data, 'services'),
            processes: array_key_exists('processes', $data)
                ? static::stringList($data, 'processes')
                : [],
            database: $data['database'] ?? null,
            createdAt: $createdAt,
            cpus: $data['cpus'] ?? null,
            memory: $data['memory'] ?? null,
            status: $data['status'] ?? 'ready',
            image: $image,
            imageDigest: $imageDigest,
            runtime: $runtime,
        );
    }

    /**
     * Return a copy with updated provisioning status.
     */
    public function withStatus(string $status): self
    {
        if (! in_array($status, ['provisioning', 'ready', 'failed'], true)) {
            throw new InvalidArgumentException('The manifest status must be provisioning, ready, or failed.');
        }

        return $this->copy(
            processes: $this->processes,
            status: $status,
            image: $this->image,
            imageDigest: $this->imageDigest,
        );
    }

    /**
     * Return a copy that records the exact image used by its container.
     */
    public function withImage(string $image, string $digest): self
    {
        return $this->copy(
            processes: $this->processes,
            status: $this->status,
            image: $image,
            imageDigest: $digest,
        );
    }

    /**
     * Return a copy with the application processes configured for a rebuild.
     *
     * @param  list<string>  $processes
     */
    public function withProcesses(array $processes): self
    {
        return $this->copy(
            processes: $processes,
            status: $this->status,
            image: $this->image,
            imageDigest: $this->imageDigest,
        );
    }

    /**
     * Compare the recorded image with the configured image available now.
     */
    public function imageOutdated(string $configuredImage, ?string $configuredDigest): ?bool
    {
        if ($this->image === null || $this->imageDigest === null) {
            return null;
        }

        if ($this->image !== $configuredImage) {
            return true;
        }

        return $configuredDigest === null ? null : $this->imageDigest !== $configuredDigest;
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
            'runtime' => $this->runtime,
            'url' => $this->url,
            'branch' => $this->branch,
            'php' => $this->php,
            'frontend' => $this->frontend,
            'expose_services' => $this->exposeServices,
            'services' => $this->services,
            'processes' => $this->processes,
            'database' => $this->database,
            'created_at' => $this->createdAt->toIso8601String(),
            'cpus' => $this->cpus,
            'memory' => $this->memory,
            'status' => $this->status,
            'image' => $this->image,
            'image_digest' => $this->imageDigest,
        ];
    }

    /**
     * Copy immutable instance metadata with updated lifecycle fields.
     *
     * @param  list<string>  $processes
     */
    protected function copy(
        array $processes,
        string $status,
        ?string $image,
        ?string $imageDigest,
    ): self {
        return new self(
            name: $this->name,
            container: $this->container,
            url: $this->url,
            branch: $this->branch,
            php: $this->php,
            frontend: $this->frontend,
            exposeServices: $this->exposeServices,
            services: $this->services,
            processes: $processes,
            database: $this->database,
            createdAt: $this->createdAt,
            cpus: $this->cpus,
            memory: $this->memory,
            status: $status,
            image: $image,
            imageDigest: $imageDigest,
            runtime: $this->runtime,
        );
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
