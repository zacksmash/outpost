<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class Processes
{
    /**
     * Vite stays on loopback while nginx exposes its public endpoint.
     */
    public const int VITE_INTERNAL_PORT = 24678;

    /**
     * Create an application process configuration reader.
     */
    public function __construct(protected readonly Repository $config) {}

    /**
     * Resolve every configured process command for an instance PHP version.
     *
     * @return array<string, list<string>>
     */
    public function commands(
        string $php,
        string $server = 'fpm',
        string $frontend = 'build',
        ?string $url = null,
        ?string $octaneServer = null,
    ): array {
        $commands = $this->managedCommands($php, $server, $frontend, $url, $octaneServer);
        $configured = $this->config->get('outpost.processes', []);

        if (! is_array($configured)) {
            throw new RuntimeException('The [outpost.processes] value must be an associative array.');
        }

        foreach ($configured as $name => $command) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
                throw new RuntimeException(
                    'Outpost process names must start with a lowercase letter and may only contain lowercase letters, numbers, dashes, and underscores.',
                );
            }

            if (array_key_exists($name, $commands)) {
                throw new RuntimeException(
                    "The [{$name}] process name is reserved by Outpost's managed {$name} process.",
                );
            }

            if (! is_array($command) || ! array_is_list($command) || $command === []) {
                throw new RuntimeException(
                    "The [outpost.processes.{$name}] command must be a non-empty list of argument strings.",
                );
            }

            $arguments = [];

            foreach ($command as $argument) {
                if (! is_string($argument)
                    || $argument === ''
                    || str_contains($argument, "\n")
                    || str_contains($argument, "\r")
                    || str_contains($argument, "\0")
                    || str_contains($argument, ';')) {
                    throw new RuntimeException(
                        "The [outpost.processes.{$name}] command must contain only non-empty, single-line argument strings without semicolons.",
                    );
                }

                $arguments[] = $argument === '@php' ? "php{$php}" : $argument;
            }

            $commands[$name] = $arguments;
        }

        return $commands;
    }

    /**
     * Build the processes managed by Outpost itself.
     *
     * @return array<string, list<string>>
     */
    protected function managedCommands(
        string $php,
        string $server,
        string $frontend,
        ?string $url,
        ?string $octaneServer,
    ): array {
        $commands = [];

        if ($server === 'octane') {
            $octaneServer ??= 'swoole';

            $serverOptions = match ($octaneServer) {
                'swoole' => [],
                'roadrunner' => ['--rpc-port=6001'],
                'frankenphp' => ['--admin-port=2019'],
                default => throw new RuntimeException(
                    'The managed Octane server must be one of: swoole, roadrunner, frankenphp.',
                ),
            };

            $commands['octane'] = [
                '/bin/bash',
                '/etc/outpost/octane-watch',
                "php{$php}",
                $this->octaneWatchPaths(),
                "php{$php}",
                'artisan',
                'octane:start',
                "--server={$octaneServer}",
                '--host=127.0.0.1',
                '--port=8000',
                ...$serverOptions,
            ];
        }

        if ($frontend === 'vite') {
            $commands['vite'] = $this->viteCommand($url);
        }

        return $commands;
    }

    /**
     * Resolve safe container paths for Outpost's polling Octane watcher.
     */
    protected function octaneWatchPaths(): string
    {
        $paths = $this->config->get('octane.watch');

        if (! is_array($paths) || ! array_is_list($paths) || $paths === []) {
            throw new RuntimeException(
                'The [octane.watch] value must be a non-empty list of application-relative paths.',
            );
        }

        $absolute = [];

        foreach ($paths as $path) {
            if (! is_string($path)
                || $path === ''
                || str_starts_with($path, '/')
                || str_contains($path, '\\')
                || str_contains($path, "\0")
                || str_contains($path, "\n")
                || str_contains($path, "\r")
                || in_array('..', explode('/', $path), true)) {
                throw new RuntimeException(
                    'Every [octane.watch] entry must be a safe, non-empty path relative to the application.',
                );
            }

            $absolute[] = '/app/'.$path;
        }

        return json_encode($absolute, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Build the managed Vite development-server command.
     *
     * @return list<string>
     */
    protected function viteCommand(?string $url): array
    {
        $port = $this->config->get('outpost.vite.port', 5173);
        $hotFile = $this->config->get('outpost.vite.hot_file', 'public/hot');

        if (! is_int($port) || $port < 1 || $port > 65535 || $port === self::VITE_INTERNAL_PORT) {
            throw new RuntimeException('The [outpost.vite.port] value must be an available integer between 1 and 65535.');
        }

        if (! is_string($hotFile)
            || $hotFile === ''
            || str_starts_with($hotFile, '/')
            || str_contains($hotFile, '\\')
            || in_array('..', explode('/', $hotFile), true)) {
            throw new RuntimeException(
                'The [outpost.vite.hot_file] value must be a safe path relative to the application.',
            );
        }

        if ($url === null || ! str_starts_with($url, 'http')) {
            throw new RuntimeException('Vite mode requires an instance URL.');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new RuntimeException('Vite mode requires an instance URL with a valid hostname.');
        }

        return [
            '/usr/local/bin/outpost-vite',
            rtrim($url, '/').":{$port}",
            $hotFile,
            'env',
            'CHOKIDAR_USEPOLLING=true',
            "__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS={$host}",
            'npm',
            'run',
            'dev',
            '--',
            '--host',
            '127.0.0.1',
            '--port',
            (string) self::VITE_INTERNAL_PORT,
            '--strictPort',
        ];
    }
}
