<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class Supervisord
{
    /**
     * Create a Supervisor configuration generator.
     */
    public function __construct(protected readonly Repository $config) {}

    /**
     * Generate the supervisord program configuration for the given instance.
     *
     * @param  array<string, list<string>>  $processes
     */
    public function generate(Manifest $manifest, array $processes = []): string
    {
        $programs = [];

        if ($manifest->uses('mysql')) {
            $address = $manifest->exposeServices ? '0.0.0.0' : '127.0.0.1';
            $programs[] = $this->program('mysql', "/usr/sbin/mysqld --user=mysql --bind-address={$address}", 10);
        }

        if ($manifest->uses('pgsql')) {
            $command = '/usr/lib/postgresql/16/bin/postgres -D /var/lib/postgresql/16/main -c config_file=/etc/postgresql/16/main/postgresql.conf';

            if ($manifest->exposeServices) {
                $command .= ' -c "listen_addresses=*"';
            }

            $programs[] = $this->program(
                'pgsql',
                $command,
                10,
                user: 'postgres',
            );
        }

        if ($manifest->uses('redis')) {
            $command = $manifest->exposeServices
                ? '/usr/bin/redis-server --bind 0.0.0.0 --protected-mode yes --requirepass '.$this->credential('password')
                : '/usr/bin/redis-server --bind 127.0.0.1';
            $programs[] = $this->program('redis', $command, 15);
        }

        if ($manifest->server === 'fpm') {
            $programs[] = $this->program(
                'php-fpm',
                "/usr/sbin/php-fpm{$manifest->php} --nodaemonize --fpm-config /etc/php/{$manifest->php}/fpm/php-fpm.conf",
                20,
            );
        }

        if ($manifest->uses('mailpit')) {
            $smtp = $manifest->exposeServices ? '0.0.0.0:1025' : '127.0.0.1:1025';
            $programs[] = $this->program(
                'mailpit',
                "/usr/local/bin/mailpit --smtp {$smtp} --listen 127.0.0.1:8026",
                25,
            );
        }

        $programs[] = $this->program('nginx', "/usr/sbin/nginx -g 'daemon off;'", 30);

        $priority = 40;

        foreach ($processes as $name => $command) {
            $programs[] = $this->program(
                "outpost-{$name}",
                '/usr/local/bin/outpost-wait '.$this->command($command),
                $priority++,
                user: 'outpost',
                directory: '/app',
                stopAsGroup: true,
            );
        }

        return implode("\n", $programs);
    }

    /**
     * Read a shell-safe sandbox credential.
     */
    protected function credential(string $key): string
    {
        $value = $this->config->get("outpost.database.{$key}");

        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value) !== 1) {
            throw new RuntimeException(
                "The [outpost.database.{$key}] value must start with a letter or number and may only contain letters, numbers, dots, dashes, and underscores.",
            );
        }

        return $value;
    }

    /**
     * Quote a shell-free argument list for Supervisor's command parser.
     *
     * @param  list<string>  $arguments
     */
    protected function command(array $arguments): string
    {
        return implode(' ', array_map(
            fn (string $argument): string => '"'.str_replace(
                ['\\', '"', '%'],
                ['\\\\', '\\"', '%%'],
                $argument,
            ).'"',
            $arguments,
        ));
    }

    /**
     * Build a single supervisord program block.
     */
    protected function program(
        string $name,
        string $command,
        int $priority,
        ?string $user = null,
        ?string $directory = null,
        bool $stopAsGroup = false,
    ): string {
        $lines = [
            "[program:{$name}]",
            "command={$command}",
            "priority={$priority}",
        ];

        if ($user !== null) {
            $lines[] = "user={$user}";
        }

        if ($directory !== null) {
            $lines[] = "directory={$directory}";
        }

        if ($stopAsGroup) {
            $lines[] = 'stopasgroup=true';
            $lines[] = 'killasgroup=true';
        }

        return implode("\n", [
            ...$lines,
            'autorestart=true',
            'stdout_logfile=/dev/stdout',
            'stdout_logfile_maxbytes=0',
            'stderr_logfile=/dev/stderr',
            'stderr_logfile_maxbytes=0',
        ])."\n";
    }
}
