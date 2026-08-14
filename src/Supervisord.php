<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class Supervisord
{
    /**
     * Generate the supervisord program configuration for the given instance.
     *
     * @param  array<string, list<string>>  $processes
     */
    public function generate(Manifest $manifest, array $processes = []): string
    {
        $programs = [];

        if ($manifest->uses('mysql')) {
            $programs[] = $this->program('mysql', '/usr/sbin/mysqld --user=mysql', 10);
        }

        if ($manifest->uses('pgsql')) {
            $programs[] = $this->program(
                'pgsql',
                '/usr/lib/postgresql/16/bin/postgres -D /var/lib/postgresql/16/main -c config_file=/etc/postgresql/16/main/postgresql.conf',
                10,
                user: 'postgres',
            );
        }

        if ($manifest->uses('redis')) {
            $programs[] = $this->program('redis', '/usr/bin/redis-server --bind 127.0.0.1', 15);
        }

        if ($manifest->server === 'fpm') {
            $programs[] = $this->program(
                'php-fpm',
                "/usr/sbin/php-fpm{$manifest->php} --nodaemonize --fpm-config /etc/php/{$manifest->php}/fpm/php-fpm.conf",
                20,
            );
        }

        if ($manifest->uses('mailpit')) {
            $programs[] = $this->program(
                'mailpit',
                '/usr/local/bin/mailpit --smtp 0.0.0.0:1025 --listen 0.0.0.0:8025',
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
                user: 'www-data',
                directory: '/app',
                stopAsGroup: true,
            );
        }

        return implode("\n", $programs);
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
