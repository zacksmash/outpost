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
    | those URLs live on. The domain must be registered once with Apple's
    | container DNS resolver; Outpost prints the exact command to run
    | whenever that registration is missing. Herd owns ".test", so
    | Outpost stays out of its way by default.
    |
    */

    'domain' => env('OUTPOST_DOMAIN', 'outpost'),

    /*
    |--------------------------------------------------------------------------
    | Base Image
    |--------------------------------------------------------------------------
    |
    | All instances boot from a single shared base image containing every
    | service Outpost knows how to run. The image is built once via the
    | "outpost:build" command, so creating an instance never triggers
    | an image build. Instances differ only by generated config.
    |
    */

    'image' => env('OUTPOST_IMAGE', 'outpost-base'),

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
