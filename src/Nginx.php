<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class Nginx
{
    /**
     * Generate the nginx configuration for the given instance.
     */
    public function generate(Manifest $manifest): string
    {
        $sections = [];

        if ($manifest->secure()) {
            $sections[] = <<<'NGINX'
            server {
                listen 80 default_server;
                server_name _;

                return 301 https://$host$request_uri;
            }
            NGINX;
        }

        $sections[] = $this->application($manifest);

        if ($manifest->uses('mailpit')) {
            $sections[] = $this->mailpit($manifest);
        }

        return implode("\n\n", $sections)."\n";
    }

    /**
     * Generate the PHP-FPM application server.
     */
    protected function application(Manifest $manifest): string
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
}
