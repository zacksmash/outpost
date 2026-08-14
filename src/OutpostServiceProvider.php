<?php

declare(strict_types=1);

namespace Outpost\Outpost;

use Illuminate\Support\ServiceProvider;
use Outpost\Outpost\Console\Commands\OutpostCommand;

class OutpostServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/outpost.php', 'outpost');

        $this->app->singleton(Outpost::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/outpost.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'outpost');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'outpost');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/outpost.php' => config_path('outpost.php'),
        ], ['outpost', 'outpost-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/outpost'),
        ], ['outpost', 'outpost-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/outpost'),
        ], ['outpost', 'outpost-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/outpost'),
        ], ['outpost', 'outpost-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['outpost', 'outpost-migrations']);

        $this->commands([
            OutpostCommand::class,
        ]);
    }
}
