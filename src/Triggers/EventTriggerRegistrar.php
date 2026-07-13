<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Padosoft\LaravelFlowConnect\Contracts\EventInputMapper;
use ReflectionClass;
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

            if ($mapperClass !== null && ! $this->isInstantiableMapper($mapperClass)) {
                $this->skip($index, sprintf('the "mapper" value must be an instantiable class implementing %s', EventInputMapper::class));

                continue;
            }

            /** @var class-string<EventInputMapper>|null $mapperClass */
            // Variadic, untyped params — NOT `function (object $event)` —
            // deliberately: Laravel's Dispatcher invokes a listener with
            // array_values($payload) when an event is fired via the string-
            // name-plus-payload form (Event::dispatch($eventClass, $payload)),
            // not necessarily a single typed object. A strictly-typed object
            // param would let PHP raise a TypeError/ArgumentCountError BEFORE
            // this closure's body ever runs — outside the try/catch below,
            // breaking the very isolation guarantee this class exists for.
            // Validating shape INSIDE the try converts every misshapen
            // dispatch into a caught, logged skip instead of an escaping
            // fatal.
            $events->listen($eventClass, function (mixed ...$payload) use ($eventClass, $flow, $mapperClass, $index): void {
                try {
                    $event = $payload[0] ?? null;

                    if (! is_object($event)) {
                        throw new InvalidArgumentException(sprintf(
                            'expected the dispatched event to be an object, got %s',
                            get_debug_type($event),
                        ));
                    }

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

    /**
     * `is_a($class, Interface::class, true)` alone accepts an INTERFACE or
     * ABSTRACT class name too (anything that "is a" the target type, not
     * only concrete implementations) — those pass this eager boot-time
     * check but fail every time the container tries to actually instantiate
     * one at fire time, degrading a single config mistake into a warning
     * logged on every matching event instead of one skip logged once.
     */
    private function isInstantiableMapper(mixed $mapperClass): bool
    {
        if (! is_string($mapperClass) || trim($mapperClass) === '' || ! class_exists($mapperClass)) {
            return false;
        }

        if (! is_a($mapperClass, EventInputMapper::class, true)) {
            return false;
        }

        return (new ReflectionClass($mapperClass))->isInstantiable();
    }

    private function skip(int|string $index, string $reason): void
    {
        Log::warning('laravel-flow-connect: event trigger config entry skipped.', [
            'config_index' => $index,
            'reason' => $reason,
        ]);
    }
}
