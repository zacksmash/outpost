<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class Endpoints
{
    /**
     * Endpoint names reserved by Outpost's built-in application and services.
     *
     * @var list<string>
     */
    protected const array RESERVED_NAMES = [
        'app',
        'application',
        'mysql',
        'pgsql',
        'redis',
        'mailpit',
    ];

    /**
     * Create an instance endpoint resolver.
     */
    public function __construct(protected readonly Repository $config) {}

    /**
     * Describe every host endpoint exposed by an instance.
     *
     * @return array<string, array<string, int|string>>
     */
    public function all(Manifest $manifest): array
    {
        $host = $this->host($manifest);
        $scheme = $manifest->secure() ? 'https' : 'http';
        $endpoints = [
            'application' => ['url' => $manifest->url],
            ...$this->previews($manifest),
        ];

        if (! $manifest->exposeServices) {
            return $endpoints;
        }

        if ($manifest->uses('mysql')) {
            $endpoints['mysql'] = $this->database($host, 'mysql', 3306);
        }

        if ($manifest->uses('pgsql')) {
            $endpoints['pgsql'] = $this->database($host, 'postgresql', 5432);
        }

        if ($manifest->uses('redis')) {
            $passwordValue = $this->credential('password');
            $password = rawurlencode($passwordValue);
            $endpoints['redis'] = [
                'host' => $host,
                'port' => 6379,
                'password' => $passwordValue,
                'url' => "redis://:{$password}@{$host}:6379",
            ];
        }

        if ($manifest->uses('mailpit')) {
            $endpoints['mailpit'] = [
                'url' => "{$scheme}://{$host}:8025",
                'smtp_host' => $host,
                'smtp_port' => 1025,
            ];
        }

        return $endpoints;
    }

    /**
     * Resolve repository-configured, same-origin review links.
     *
     * @return array<string, array<string, string>>
     */
    protected function previews(Manifest $manifest): array
    {
        $configured = $this->config->get('outpost.previews', []);

        if (! is_array($configured)) {
            return [];
        }

        $previews = [];

        foreach ($configured as $name => $definition) {
            try {
                $preview = $this->preview($manifest, $name, $definition);
                $previews[$preview['name']] = $preview['endpoint'];
            } catch (RuntimeException) {
                continue;
            }
        }

        return $previews;
    }

    /**
     * Describe an exposed SQL database.
     *
     * @return array<string, int|string>
     */
    protected function database(string $host, string $scheme, int $port): array
    {
        $database = $this->credential('database');
        $username = $this->credential('username');
        $password = $this->credential('password');
        $url = sprintf(
            '%s://%s:%s@%s:%d/%s',
            $scheme,
            rawurlencode($username),
            rawurlencode($password),
            $host,
            $port,
            rawurlencode($database),
        );

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'url' => $url,
        ];
    }

    /**
     * Resolve a named browser endpoint.
     */
    public function browser(Manifest $manifest, string $name): string
    {
        if (in_array($name, ['app', 'application'], true)) {
            return $manifest->url;
        }

        $endpoint = $this->all($manifest)[$name] ?? null;
        $url = is_array($endpoint) ? ($endpoint['url'] ?? null) : null;

        if (! is_string($url) && ! in_array($name, self::RESERVED_NAMES, true)) {
            $configured = $this->config->get('outpost.previews', []);

            if (is_array($configured) && array_key_exists($name, $configured)) {
                $url = $this->preview($manifest, $name, $configured[$name])['endpoint']['url'];
            }
        }

        if (! is_string($url)) {
            throw new RuntimeException(
                "The [{$manifest->name}] instance does not expose a [{$name}] browser endpoint.",
            );
        }

        return $url;
    }

    /**
     * Validate and resolve one configured preview.
     *
     * @return array{name: string, endpoint: array<string, string>}
     */
    protected function preview(Manifest $manifest, mixed $name, mixed $definition): array
    {
        if (! is_string($name)
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1
            || in_array($name, self::RESERVED_NAMES, true)) {
            throw new RuntimeException(
                'Outpost preview names must start with a lowercase letter, contain only lowercase letters, numbers, dashes, or underscores, and not use a built-in endpoint name.',
            );
        }

        if (! is_array($definition)
            || array_is_list($definition)
            || array_diff(array_keys($definition), ['path', 'note']) !== []) {
            throw new RuntimeException(
                "The [outpost.previews.{$name}] value must contain only a path and optional note.",
            );
        }

        $path = $definition['path'] ?? null;

        if (! is_string($path)
            || preg_match('#^/(?!/)#D', $path) !== 1
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1) {
            throw new RuntimeException(
                "The [outpost.previews.{$name}.path] value must be a same-origin path beginning with one slash and containing no whitespace or control characters.",
            );
        }

        $note = $definition['note'] ?? null;

        if ($note !== null
            && (! is_string($note)
                || trim($note) === ''
                || preg_match('/[\x00-\x1F\x7F]/', $note) === 1)) {
            throw new RuntimeException(
                "The [outpost.previews.{$name}.note] value must be a non-empty, single-line string when present.",
            );
        }

        return [
            'name' => $name,
            'endpoint' => [
                'url' => $manifest->url.$path,
                'path' => $path,
                ...($note === null ? [] : ['note' => $note]),
            ],
        ];
    }

    /**
     * Get the hostname from a trusted manifest URL.
     */
    protected function host(Manifest $manifest): string
    {
        $host = parse_url($manifest->url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new RuntimeException("The [{$manifest->name}] instance URL has no valid hostname.");
        }

        return $host;
    }

    /**
     * Read an instance credential.
     */
    protected function credential(string $key): string
    {
        $value = $this->config->get("outpost.database.{$key}");

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The [outpost.database.{$key}] value must be a non-empty string.");
        }

        return $value;
    }
}
