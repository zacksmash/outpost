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
}
