<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlowConnect\Http\Client\EgressGuard;
use Padosoft\LaravelFlowConnect\Http\Client\HttpConnectionRegistry;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestController;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestVerifier;
use Padosoft\LaravelFlowConnect\Nodes\HttpRequestNode;
use Padosoft\LaravelFlowConnect\Triggers\EventTrigger;
use Padosoft\LaravelFlowConnect\Triggers\EventTriggerRegistrar;
use Padosoft\LaravelFlowConnect\Triggers\ScheduleTrigger;
use Padosoft\LaravelFlowConnect\Triggers\ScheduleTriggerRegistrar;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTrigger;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTriggerRegistrar;

/**
 * @internal
 */
final class LaravelFlowConnectServiceProvider extends ServiceProvider
{
    /**
     * Graph node handlers this package contributes to core's registry.
     *
     * @var list<class-string>
     */
    private const NODE_HANDLERS = [
        HttpRequestNode::class,
    ];

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

        $this->app->singleton(WebhookTrigger::class);
        $this->app->singleton(WebhookRequestVerifier::class, fn (Container $app): WebhookRequestVerifier => new WebhookRequestVerifier($app->make(CacheRepository::class)));
        $this->app->singleton(WebhookRequestController::class);
        $this->app->singleton(WebhookTriggerRegistrar::class);

        // Not singletons: both read config at construction, so a fresh instance
        // per node resolution always sees the current allow-list / connections.
        $this->app->bind(HttpConnectionRegistry::class, fn (Container $app): HttpConnectionRegistry => new HttpConnectionRegistry($app->make(ConfigRepository::class)));
        $this->app->bind(EgressGuard::class, function (Container $app): EgressGuard {
            $hosts = $app->make(ConfigRepository::class)->get('laravel-flow-connect.http.allowed_hosts', []);

            return new EgressGuard(is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : []);
        });
    }

    /**
     * Core has no dedicated registration API: a package appends to
     * `laravel-flow.nodes.handlers`. Done in boot(), not register(): core's own
     * config merge is shallow, so writing `laravel-flow.nodes.*` during
     * register() could drop `nodes.discovery` if this provider ran first. The
     * NodeRegistry singleton is resolved lazily, so boot() is early enough.
     */
    private function registerNodeHandlers(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        /** @var list<class-string> $existing */
        $existing = array_values((array) $config->get('laravel-flow.nodes.handlers', []));

        $config->set('laravel-flow.nodes.handlers', array_values(array_unique([...$existing, ...self::NODE_HANDLERS])));
    }

    public function boot(): void
    {
        $this->registerNodeHandlers();

        // Event-trigger registration fires on EVERY request/job, not just
        // console — unlike the schedule registration below, it must NOT be
        // gated on runningInConsole(). Dispatcher::listen() only stores a
        // closure keyed by event class; it forces no heavy resolution the
        // way Schedule::class does (see below), so there is nothing to defer
        // and no cost to registering it unconditionally in every process.
        /** @var array<array-key, mixed> $entries */
        $entries = (array) $this->app->make(ConfigRepository::class)->get('laravel-flow-connect.event_triggers', []);
        $this->app->make(EventTriggerRegistrar::class)->register($this->app->make(Dispatcher::class), $entries);

        // Webhook route registration, same reasoning as event triggers above:
        // Router::post() only stores a route definition, forcing no eager
        // resolution — so this runs unconditionally too (routes must exist
        // for route:list/route caching AND actual HTTP handling alike, not
        // only when this specific process happens to serve the request).
        /** @var array<string, mixed> $webhookConfig */
        $webhookConfig = (array) $this->app->make(ConfigRepository::class)->get('laravel-flow-connect.webhook', []);
        $this->app->make(WebhookTriggerRegistrar::class)->register($this->app->make(Router::class), $webhookConfig);

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
