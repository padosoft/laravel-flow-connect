<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Padosoft\LaravelFlow\Contracts\FlowTrigger;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;

/**
 * Cron-driven {@see FlowTrigger}: `fire()` hands its (already-mapped, static
 * per-entry) input straight to the engine's `dispatch()` method via the
 * `Flow` facade. Registration (reading `config('laravel-flow-connect.
 * schedule_triggers')`, validating each entry, and wiring it onto Laravel's
 * `Schedule`) is {@see ScheduleTriggerRegistrar}'s job, not this class's —
 * this class is the single fire-and-forget seam every registered cron entry
 * calls through, kept separate so it stays a plain, container-resolvable
 * implementation of the shared core contract.
 *
 * @internal
 */
final class ScheduleTrigger implements FlowTrigger
{
    public function fire(string $flowName, array $input = [], ?FlowExecutionOptions $options = null): void
    {
        Flow::dispatch($flowName, $input, $options);
    }
}
