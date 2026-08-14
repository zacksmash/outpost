<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class Supervisord
{
    /**
     * Generate the supervisord program configuration for the given instance.
     */
    public function generate(Manifest $manifest): string
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
            $programs[] = $this->program('redis', '/usr/bin/redis-server --protected-mode no', 15);
        }

        $programs[] = $this->program(
            'php-fpm',
            "/usr/sbin/php-fpm{$manifest->php} --nodaemonize --fpm-config /etc/php/{$manifest->php}/fpm/php-fpm.conf",
            20,
        );

        if ($manifest->uses('mailpit')) {
            $programs[] = $this->program(
                'mailpit',
                '/usr/local/bin/mailpit --smtp 0.0.0.0:1025 --listen 0.0.0.0:8025',
                25,
            );
        }

        $programs[] = $this->program('nginx', "/usr/sbin/nginx -g 'daemon off;'", 30);

        return implode("\n", $programs);
    }

    /**
     * Build a single supervisord program block.
     */
    protected function program(string $name, string $command, int $priority, ?string $user = null): string
    {
        $lines = [
            "[program:{$name}]",
            "command={$command}",
            "priority={$priority}",
        ];

        if ($user !== null) {
            $lines[] = "user={$user}";
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
