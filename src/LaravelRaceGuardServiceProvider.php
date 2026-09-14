<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard;

use Illuminate\Support\ServiceProvider;
use Nayvo\LaravelRaceGuard\Console\RaceCheckCommand;

class LaravelRaceGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/race-guard.php',
            'race-guard',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/race-guard.php' => $this->app->configPath('race-guard.php'),
            ], 'race-guard-config');

            $this->commands([
                RaceCheckCommand::class,
            ]);
        }
    }
}
