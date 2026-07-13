<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlowConnect\Triggers\ScheduleTrigger;
use Padosoft\LaravelFlowConnect\Triggers\ScheduleTriggerRegistrar;

/**
 * @internal
 */
final class LaravelFlowConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-flow-connect.php',
            'laravel-flow-connect',
        );

        $this->app->singleton(ScheduleTrigger::class);
        $this->app->singleton(ScheduleTriggerRegistrar::class, fn (Container $app): ScheduleTriggerRegistrar => new ScheduleTriggerRegistrar($app->make(ScheduleTrigger::class)));
    }

    public function boot(): void
    {
        // Publishing and schedule registration only matter for console/CLI
        // execution (artisan vendor:publish, schedule:run, schedule:list) —
        // guard both so an ordinary HTTP request's boot doesn't pay for
        // resolving Schedule::class (which lazily builds the whole
        // ConsoleKernel) or registering callbacks nothing will ever run.
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-flow-connect.php' => $this->app->configPath('laravel-flow-connect.php'),
        ], 'laravel-flow-connect-config');

        // Schedule::class is a container singleton lazily built by
        // ConsoleKernel::resolveConsoleSchedule() (see Laravel's
        // FoundationServiceProvider) — safe to resolve directly here rather
        // than deferring, since resolving it is exactly what triggers that
        // lazy construction.
        $schedule = $this->app->make(Schedule::class);
        $config = $this->app->make(ConfigRepository::class);
        /** @var array<int, mixed> $entries */
        $entries = (array) $config->get('laravel-flow-connect.schedule_triggers', []);

        $this->app->make(ScheduleTriggerRegistrar::class)->register($schedule, $entries);
    }
}
