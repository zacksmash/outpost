<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class Endpoints
{
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
        $name = $name === 'application' ? 'app' : $name;
        $endpoint = $name === 'app'
            ? $this->all($manifest)['application']
            : ($this->all($manifest)[$name] ?? null);
        $url = is_array($endpoint) ? ($endpoint['url'] ?? null) : null;

        if (! is_string($url)) {
            throw new RuntimeException(
                "The [{$manifest->name}] instance does not expose a [{$name}] browser endpoint.",
            );
        }

        return $url;
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
     * Read a sandbox credential.
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
