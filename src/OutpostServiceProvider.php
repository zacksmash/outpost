<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\ServiceProvider;

class OutpostServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/outpost.php', 'outpost');
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
