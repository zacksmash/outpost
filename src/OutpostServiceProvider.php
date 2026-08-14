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

        $this->app->singleton(Outposts::class, function (Application $app) {
            $path = $app->make('config')->string('outpost.path');

            return new Outposts(
                str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $app->basePath($path),
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
    }
}
