<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlowConnect\Triggers\EventTrigger;
use Padosoft\LaravelFlowConnect\Triggers\EventTriggerRegistrar;
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

        $this->app->singleton(EventTrigger::class);
        $this->app->singleton(EventTriggerRegistrar::class, fn (Container $app): EventTriggerRegistrar => new EventTriggerRegistrar($app->make(EventTrigger::class), $app));
    }

    public function boot(): void
    {
        // Event-trigger registration fires on EVERY request/job, not just
        // console — unlike the schedule registration below, it must NOT be
        // gated on runningInConsole(). Dispatcher::listen() only stores a
        // closure keyed by event class; it forces no heavy resolution the
        // way Schedule::class does (see below), so there is nothing to defer
        // and no cost to registering it unconditionally in every process.
        /** @var array<array-key, mixed> $entries */
        $entries = (array) $this->app->make(ConfigRepository::class)->get('laravel-flow-connect.event_triggers', []);
        $this->app->make(EventTriggerRegistrar::class)->register($this->app->make(Dispatcher::class), $entries);

        // Publishing and schedule registration only matter for console/CLI
        // execution.
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-flow-connect.php' => $this->app->configPath('laravel-flow-connect.php'),
        ], 'laravel-flow-connect-config');

        // afterResolving(), NOT app->make(): resolving Schedule::class
        // eagerly here would force it on EVERY console command this package
        // ships alongside (migrate, queue:work, tinker, ...), not just
        // schedule:run/schedule:list — and resolving it immediately triggers
        // ConsoleKernel::resolveConsoleSchedule(), which builds the HOST
        // APPLICATION's entire schedule (every cron entry it defines, not
        // just ours). afterResolving() instead registers a callback that
        // fires ONLY if/when something else in this process actually
        // resolves Schedule::class (which in practice means the scheduler
        // commands, and command REALLY does need it) — so an unrelated
        // artisan command run alongside this package never pays that cost.
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app->make(ConfigRepository::class);
            /** @var array<array-key, mixed> $entries */
            $entries = (array) $config->get('laravel-flow-connect.schedule_triggers', []);

            $this->app->make(ScheduleTriggerRegistrar::class)->register($schedule, $entries);
        });
    }
}
