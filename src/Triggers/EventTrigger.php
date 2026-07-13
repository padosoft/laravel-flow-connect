<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Padosoft\LaravelFlow\Contracts\FlowTrigger;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;

/**
 * Host-event-driven {@see FlowTrigger}: `fire()` hands its (already-mapped)
 * input straight to the engine's `dispatch()` method via the `Flow` facade —
 * byte-identical to {@see ScheduleTrigger}'s body, since `FlowTrigger::fire()`
 * carries no trigger-type-specific behavior itself. Kept as its own class
 * (rather than reusing `ScheduleTrigger`) for per-trigger-type discoverability;
 * once a third trigger type needs the identical body, extracting a single
 * shared implementation becomes the right call.
 *
 * Registration (reading `config('laravel-flow-connect.event_triggers')`,
 * validating each entry, and wiring a listener onto the event dispatcher) is
 * {@see EventTriggerRegistrar}'s job, not this class's.
 *
 * @internal
 */
final class EventTrigger implements FlowTrigger
{
    public function fire(string $flowName, array $input = [], ?FlowExecutionOptions $options = null): void
    {
        Flow::dispatch($flowName, $input, $options);
    }
}
