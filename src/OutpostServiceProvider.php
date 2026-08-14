<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class OutpostServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/outpost.php', 'outpost');

        $this->app->singleton(Detector::class, function (Application $app) {
            return new Detector($app->make('config'), $app->basePath());
        });

        $this->app->singleton(Doctor::class, function (Application $app) {
            return new Doctor(
                $app->make(Host::class),
                $app->make(Runtime::class),
                $app->make(Git::class),
                $app->make('files'),
                $app->basePath(),
                $app->make('config'),
            );
        });

        $this->app->singleton(Git::class, function (Application $app) {
            return new Git($app->basePath());
        });

        $this->app->singleton(PathRepositories::class, function () {
            $home = $_SERVER['HOME'] ?? null;

            return new PathRepositories(is_string($home) ? $home : null);
        });

        $this->app->singleton(Provisioner::class);

        $this->app->singleton(Processes::class);

        $this->app->singleton(Runtime::class);

        $this->app->singleton(Outposts::class, function (Application $app) {
            $path = $app->make('config')->string('outpost.path');

            return new Outposts(
                str_starts_with($path, '/') ? $path : $app->basePath($path),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/outpost.php' => config_path('outpost.php'),
        ], ['outpost', 'outpost-config']);

        $this->commands([
            Console\Commands\BuildCommand::class,
            Console\Commands\DoctorCommand::class,
            Console\Commands\InstallCommand::class,
            Console\Commands\ListCommand::class,
            Console\Commands\LogsCommand::class,
            Console\Commands\OpenCommand::class,
            Console\Commands\OutpostCommand::class,
            Console\Commands\PullCommand::class,
            Console\Commands\RemoveCommand::class,
            Console\Commands\ShellCommand::class,
            Console\Commands\StartCommand::class,
            Console\Commands\StopCommand::class,
        ]);
    }
}
