<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class Nginx
{
    /**
     * Generate the nginx server configuration for the given instance.
     */
    public function generate(Manifest $manifest): string
    {
        if ($manifest->server === 'octane') {
            return $this->octane();
        }

        return <<<NGINX
        server {
            listen 80 default_server;
            server_name _;

            root /app/public;
            index index.php;

            charset utf-8;
            client_max_body_size 256m;

            location / {
                try_files \$uri \$uri/ /index.php?\$query_string;
            }

            location = /favicon.ico { access_log off; log_not_found off; }
            location = /robots.txt { access_log off; log_not_found off; }

            error_page 404 /index.php;

            location ~ ^/index\\.php(/|$) {
                fastcgi_pass unix:/run/php/php{$manifest->php}-fpm.sock;
                fastcgi_buffer_size 32k;
                fastcgi_buffers 8 32k;
                fastcgi_busy_buffers_size 64k;
                fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
                include fastcgi_params;
            }

            location ~ /\\.(?!well-known).* {
                deny all;
            }
        }

        NGINX;
    }

    /**
     * Generate an nginx reverse proxy for Laravel Octane.
     */
    protected function octane(): string
    {
        return <<<'NGINX'
        map $http_upgrade $connection_upgrade {
            default upgrade;
            '' close;
        }

        server {
            listen 80 default_server;
            server_name _;

            root /app/public;
            index index.php;

            charset utf-8;
            client_max_body_size 256m;

            location / {
                try_files $uri $uri/ @octane;
            }

            location = /favicon.ico { access_log off; log_not_found off; }
            location = /robots.txt { access_log off; log_not_found off; }

            error_page 404 /index.php;

            location @octane {
                set $suffix "";

                if ($uri = /index.php) {
                    set $suffix ?$query_string;
                }

                proxy_http_version 1.1;
                proxy_set_header Host $http_host;
                proxy_set_header Scheme $scheme;
                proxy_set_header SERVER_PORT $server_port;
                proxy_set_header REMOTE_ADDR $remote_addr;
                proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
                proxy_set_header Upgrade $http_upgrade;
                proxy_set_header Connection $connection_upgrade;

                proxy_pass http://127.0.0.1:8000$suffix;
            }

            location ~ /\.(?!well-known).* {
                deny all;
            }
        }

        NGINX;
    }
}
