<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Outpost Domain
    |--------------------------------------------------------------------------
    |
    | Every instance is reachable at its own local URL, such as
    | "http://feature-billing-app.outpost". This value is the local domain
    | those URLs live on. It must match the machine-wide publication
    | domain in ~/.config/container/config.toml and be registered
    | with the container DNS resolver — Outpost verifies the live
    | runtime value at creation time and prints the exact fix when
    | they disagree or a config change still needs a restart.
    |
    */

    'domain' => env('OUTPOST_DOMAIN', 'outpost'),

    /*
    |--------------------------------------------------------------------------
    | Base Image
    |--------------------------------------------------------------------------
    |
    | All instances boot from a single versioned base image containing every
    | service Outpost knows how to run. The guided installer pulls the image
    | from GitHub Container Registry. Use "outpost:build" when you need a
    | customized local image instead. Instances differ only by config.
    |
    */

    'image' => env('OUTPOST_IMAGE', 'ghcr.io/zacksmash/outpost:0.1.0'),

    /*
    |--------------------------------------------------------------------------
    | Container DNS
    |--------------------------------------------------------------------------
    |
    | Apple's container runtime does not provide containers with a working
    | DNS resolver by default, which breaks outbound traffic like package
    | installation. This nameserver is passed to every image build and
    | container boot so instances can reach the outside world.
    |
    */

    'dns' => env('OUTPOST_DNS', '1.1.1.1'),

    /*
    |--------------------------------------------------------------------------
    | Instance Path
    |--------------------------------------------------------------------------
    |
    | Instances live in this directory at the root of your project. Each
    | instance keeps its git worktree, manifest, and generated runtime
    | configuration under its own subdirectory. Outpost adds this
    | path to your .gitignore file automatically.
    |
    */

    'path' => env('OUTPOST_PATH', '.outpost'),

    /*
    |--------------------------------------------------------------------------
    | Instance Resources
    |--------------------------------------------------------------------------
    |
    | Apple's runtime otherwise inherits a machine-wide default that may be as
    | low as 1 GB. Outpost applies predictable per-instance limits with enough
    | room for Composer, Laravel, Octane, Vite, and detected services together.
    |
    */

    'resources' => [
        'cpus' => (int) env('OUTPOST_CPUS', 4),
        'memory' => env('OUTPOST_MEMORY', '2G'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PHP Versions
    |--------------------------------------------------------------------------
    |
    | These PHP versions are installed in the base image. When an instance
    | is created, Outpost selects the highest version that satisfies the
    | application's composer.json constraint. Adding a version here
    | requires rebuilding the base image via "outpost:build".
    |
    */

    'php' => ['8.4', '8.5'],

    /*
    |--------------------------------------------------------------------------
    | Web Server
    |--------------------------------------------------------------------------
    |
    | "auto" runs Laravel Octane when the application exposes its config and
    | otherwise uses PHP-FPM. Set "fpm" to keep an Octane application on
    | the traditional request model, or "octane" to require Octane.
    |
    */

    'server' => env('OUTPOST_SERVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Frontend Development
    |--------------------------------------------------------------------------
    |
    | "build" installs dependencies and builds production assets once.
    | "vite" keeps the dev server and HMR running with the instance.
    | "none" skips npm entirely. Vite uses the conventional hot file by
    | default; customize it here when the application does the same.
    |
    */

    'frontend' => env('OUTPOST_FRONTEND', 'build'),

    'vite' => [
        'port' => (int) env('OUTPOST_VITE_PORT', 5173),
        'hot_file' => 'public/hot',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local HTTPS
    |--------------------------------------------------------------------------
    |
    | "auto" uses trusted HTTPS after "outpost:certify" creates a wildcard
    | certificate, while retaining an HTTP fallback on a fresh install.
    | Set true to require the certificate or false to always use HTTP.
    |
    */

    'https' => env('OUTPOST_HTTPS', 'auto'),

    'tls' => [
        'path' => env('OUTPOST_TLS_PATH', '.outpost/tls'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | When null, Outpost inspects your application's configuration to
    | determine which services each instance needs: your database
    | connection, Redis usage, and mail transport. Set an array
    | such as ["mysql", "redis"] to skip detection entirely.
    |
    */

    'services' => null,

    /*
    |--------------------------------------------------------------------------
    | Direct Service Access
    |--------------------------------------------------------------------------
    |
    | Expose detected databases, Redis, Mailpit SMTP, and Mailpit's web UI on
    | the instance hostname using their standard ports. Every instance has
    | its own private IP, so the ports never collide on the host.
    |
    */

    'expose_services' => env('OUTPOST_EXPOSE_SERVICES', true),

    /*
    |--------------------------------------------------------------------------
    | Application Processes
    |--------------------------------------------------------------------------
    |
    | Long-running commands are supervised with the instance and start only
    | after provisioning succeeds. Each command is a shell-free argument list;
    | use "@php" to select the same PHP version as the application.
    |
    | Examples: queue workers, schedule:work, Horizon, or another daemon.
    |
    */

    'processes' => [
        // 'queue' => ['@php', 'artisan', 'queue:work', '--sleep=1', '--tries=1'],
        // 'scheduler' => ['@php', 'artisan', 'schedule:work'],
        // 'horizon' => ['@php', 'artisan', 'horizon'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Instance Database
    |--------------------------------------------------------------------------
    |
    | When an instance runs a database service, it uses this database and
    | these credentials. They are baked into the base image by the
    | "outpost:build" command and written into each instance's
    | .env file, so changing them requires an image rebuild.
    |
    */

    'database' => [
        'database' => 'outpost',
        'username' => 'outpost',
        'password' => 'password',
    ],

    /*
    |--------------------------------------------------------------------------
    | Boot Timeout
    |--------------------------------------------------------------------------
    |
    | After booting a container, Outpost waits for the instance to answer
    | HTTP before provisioning it or printing its URL. This value is the
    | number of seconds to wait before giving up and reporting what
    | happened. Slower machines may want a little more headroom.
    |
    */

    'timeout' => (int) env('OUTPOST_TIMEOUT', 60),

];
