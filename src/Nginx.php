<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class Nginx
{
    /**
     * Create an nginx configuration generator.
     */
    public function __construct(protected readonly Repository $config) {}

    /**
     * Generate the nginx configuration for the given instance.
     */
    public function generate(Manifest $manifest): string
    {
        $sections = [];

        if ($manifest->server === 'octane' || $manifest->frontend === 'vite') {
            $sections[] = <<<'NGINX'
            map $http_upgrade $connection_upgrade {
                default upgrade;
                '' close;
            }
            NGINX;
        }

        if ($manifest->secure()) {
            $sections[] = <<<'NGINX'
            server {
                listen 80 default_server;
                server_name _;

                return 301 https://$host$request_uri;
            }
            NGINX;
        }

        $sections[] = $manifest->server === 'octane'
            ? $this->octane($manifest)
            : $this->fpm($manifest);

        if ($manifest->frontend === 'vite') {
            $sections[] = $this->vite($manifest);
        }

        if ($manifest->uses('mailpit')) {
            $sections[] = $this->mailpit($manifest);
        }

        return implode("\n\n", $sections)."\n";
    }

    /**
     * Generate the traditional PHP-FPM application server.
     */
    protected function fpm(Manifest $manifest): string
    {
        return implode("\n", [
            'server {',
            ...$this->listener($manifest, default: true),
            '    server_name _;',
            '',
            '    root /app/public;',
            '    index index.php;',
            '',
            '    charset utf-8;',
            '    client_max_body_size 256m;',
            '',
            '    location / {',
            '        try_files $uri $uri/ /index.php?$query_string;',
            '    }',
            '',
            '    location = /favicon.ico { access_log off; log_not_found off; }',
            '    location = /robots.txt { access_log off; log_not_found off; }',
            '',
            '    error_page 404 /index.php;',
            '',
            '    location ~ ^/index\\.php(/|$) {',
            "        fastcgi_pass unix:/run/php/php{$manifest->php}-fpm.sock;",
            '        fastcgi_buffer_size 32k;',
            '        fastcgi_buffers 8 32k;',
            '        fastcgi_busy_buffers_size 64k;',
            '        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;',
            '        fastcgi_param HTTPS $https if_not_empty;',
            '        include fastcgi_params;',
            '    }',
            '',
            '    location ~ \\.php$ {',
            '        return 404;',
            '    }',
            '',
            '    location ~ /\\.(?!well-known).* {',
            '        deny all;',
            '    }',
            '}',
        ]);
    }

    /**
     * Generate an nginx reverse proxy for Laravel Octane.
     */
    protected function octane(Manifest $manifest): string
    {
        return implode("\n", [
            'server {',
            ...$this->listener($manifest, default: true),
            '    server_name _;',
            '',
            '    root /app/public;',
            '    index index.php;',
            '',
            '    charset utf-8;',
            '    client_max_body_size 256m;',
            '',
            '    location / {',
            '        try_files $uri $uri/ @octane;',
            '    }',
            '',
            '    location = /index.php {',
            '        try_files /not_exists @octane;',
            '    }',
            '',
            '    location = /favicon.ico { access_log off; log_not_found off; }',
            '    location = /robots.txt { access_log off; log_not_found off; }',
            '',
            '    error_page 404 /index.php;',
            '',
            '    location @octane {',
            '        set $suffix "";',
            '',
            '        if ($uri = /index.php) {',
            '            set $suffix ?$query_string;',
            '        }',
            '',
            ...$this->proxyHeaders(),
            '        proxy_buffer_size 32k;',
            '        proxy_buffers 8 32k;',
            '        proxy_busy_buffers_size 64k;',
            '',
            '        proxy_pass http://127.0.0.1:8000$suffix;',
            '    }',
            '',
            '    location ~ \\.php$ {',
            '        return 404;',
            '    }',
            '',
            '    location ~ /\\.(?!well-known).* {',
            '        deny all;',
            '    }',
            '}',
        ]);
    }

    /**
     * Generate the public Vite and HMR endpoint.
     */
    protected function vite(Manifest $manifest): string
    {
        $port = $this->vitePort();

        return implode("\n", [
            'server {',
            ...$this->listener($manifest, port: $port),
            '    server_name _;',
            '',
            '    location / {',
            ...$this->proxyHeaders(),
            '',
            '        proxy_pass http://127.0.0.1:'.Processes::VITE_INTERNAL_PORT.';',
            '    }',
            '}',
        ]);
    }

    /**
     * Generate the public Mailpit web endpoint.
     */
    protected function mailpit(Manifest $manifest): string
    {
        return implode("\n", [
            'server {',
            ...$this->listener(
                $manifest,
                port: 8025,
                address: $manifest->exposeServices ? null : '127.0.0.1',
            ),
            '    server_name _;',
            '',
            '    location / {',
            '        proxy_http_version 1.1;',
            '        proxy_set_header Host $http_host;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            '',
            '        proxy_pass http://127.0.0.1:8026;',
            '    }',
            '}',
        ]);
    }

    /**
     * Build a listener, including TLS configuration when selected.
     *
     * @return list<string>
     */
    protected function listener(
        Manifest $manifest,
        int $port = 80,
        bool $default = false,
        ?string $address = null,
    ): array {
        $suffix = $default ? ' default_server' : '';
        $socket = $address === null ? (string) $port : "{$address}:{$port}";

        if (! $manifest->secure()) {
            return ["    listen {$socket}{$suffix};"];
        }

        if ($port === 80) {
            $port = 443;
            $socket = $address === null ? (string) $port : "{$address}:{$port}";
        }

        return [
            "    listen {$socket} ssl{$suffix};",
            '    ssl_certificate /etc/outpost/tls/certificate.pem;',
            '    ssl_certificate_key /etc/outpost/tls/key.pem;',
            '    ssl_protocols TLSv1.2 TLSv1.3;',
        ];
    }

    /**
     * Build websocket-aware reverse-proxy headers.
     *
     * @return list<string>
     */
    protected function proxyHeaders(): array
    {
        return [
            '        proxy_http_version 1.1;',
            '        proxy_set_header Host $http_host;',
            '        proxy_set_header Scheme $scheme;',
            '        proxy_set_header SERVER_PORT $server_port;',
            '        proxy_set_header REMOTE_ADDR $remote_addr;',
            '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            '        proxy_set_header X-Forwarded-Port $server_port;',
            '        proxy_set_header Upgrade $http_upgrade;',
            '        proxy_set_header Connection $connection_upgrade;',
        ];
    }

    /**
     * Read the validated public Vite port.
     */
    protected function vitePort(): int
    {
        $port = $this->config->get('outpost.vite.port', 5173);

        if (! is_int($port) || $port < 1 || $port > 65535 || $port === Processes::VITE_INTERNAL_PORT) {
            throw new RuntimeException('The [outpost.vite.port] value must be an available integer between 1 and 65535.');
        }

        return $port;
    }
}
