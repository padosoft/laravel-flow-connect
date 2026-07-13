<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlowConnect\Contracts\EventInputMapper;
use Throwable;

/**
 * Reads `config('laravel-flow-connect.event_triggers')` and registers a
 * listener onto the event {@see Dispatcher} for each valid entry. Validation
 * is EAGER (at registration time, i.e. application boot): a malformed
 * `event`/`flow`/`mapper` value is logged as a warning and SKIPPED, never
 * registered — one bad config entry must never prevent the rest of the
 * registrations (or the host application's boot) from completing.
 *
 * Unlike {@see ScheduleTriggerRegistrar}, this class registers directly in
 * the service provider's `boot()` with no `afterResolving()` deferral:
 * `Dispatcher::listen()` only stores a closure keyed by event class, it does
 * not force construction of anything heavy (contrast `Schedule::class`,
 * whose resolution triggers building the HOST APPLICATION's entire cron
 * schedule) — so there is nothing to defer.
 *
 * A registered listener's failure — the mapper THROWING, or `EventTrigger::
 * fire()` itself throwing — is caught and logged, NEVER allowed to escape.
 * This is a stronger requirement than the schedule trigger's equivalent
 * catch: a scheduled task runs inside a process this package effectively
 * owns, but an event listener runs INSIDE THE HOST APPLICATION'S OWN
 * event-dispatch call stack — an uncaught exception here would break
 * whatever unrelated code path fired the event, not just this trigger.
 *
 * @internal
 */
final class EventTriggerRegistrar
{
    public function __construct(
        private readonly EventTrigger $trigger,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<array-key, mixed>  $entries  config-sourced (a raw array cast may yield string keys too), so each entry's shape is only an ASSUMPTION until validated below — a non-array entry is skipped, not trusted
     */
    public function register(Dispatcher $events, array $entries): void
    {
        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                $this->skip($index, 'the config entry itself must be an array');

                continue;
            }

            $eventClass = $entry['event'] ?? null;
            $flow = $entry['flow'] ?? null;
            $mapperClass = $entry['mapper'] ?? null;

            if (! is_string($eventClass) || trim($eventClass) === '' || ! class_exists($eventClass)) {
                $this->skip($index, 'the "event" key must be an existing class-string');

                continue;
            }

            if (! is_string($flow) || trim($flow) === '') {
                $this->skip($index, 'the "flow" key must be a non-empty string');

                continue;
            }

            if ($mapperClass !== null && (! is_string($mapperClass) || trim($mapperClass) === '' || ! is_a($mapperClass, EventInputMapper::class, true))) {
                $this->skip($index, sprintf('the "mapper" value must be a class-string implementing %s', EventInputMapper::class));

                continue;
            }

            /** @var class-string<EventInputMapper>|null $mapperClass */
            $events->listen($eventClass, function (object $event) use ($eventClass, $flow, $mapperClass, $index): void {
                try {
                    $input = $mapperClass !== null
                        ? $this->container->make($mapperClass)->map($event)
                        : [];

                    $this->trigger->fire($flow, $input);
                } catch (Throwable $e) {
                    // Unlike a persisted infrastructure exception (a
                    // QueryException can embed SQL + bound params), this
                    // Throwable is HOST-CONTROLLED user code (the configured
                    // mapper, or a flow's own input validation) — its
                    // message IS the "logged reason" this trigger type's
                    // gate criterion requires, not a secret to withhold.
                    Log::warning('laravel-flow-connect: event trigger mapping/fire failed.', [
                        'event' => $eventClass,
                        'flow' => $flow,
                        'config_index' => $index,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);
                }
            });
        }
    }

    private function skip(int|string $index, string $reason): void
    {
        Log::warning('laravel-flow-connect: event trigger config entry skipped.', [
            'config_index' => $index,
            'reason' => $reason,
        ]);
    }
}
