<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Padosoft\LaravelFlow\Contracts\FlowTrigger;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;

/**
 * Inbound-webhook-driven {@see FlowTrigger}: `fire()` hands its (already-
 * mapped) input straight to the engine's `dispatch()` method via the `Flow`
 * facade — byte-identical to {@see ScheduleTrigger}/{@see EventTrigger}'s
 * body, since `FlowTrigger::fire()` carries no trigger-type-specific
 * behavior itself.
 *
 * Request verification (signature, replay window), route registration, and
 * payload mapping are {@see WebhookTriggerRegistrar}'s job, not this
 * class's.
 *
 * @internal
 */
final class WebhookTrigger implements FlowTrigger
{
    public function fire(string $flowName, array $input = [], ?FlowExecutionOptions $options = null): void
    {
        Flow::dispatch($flowName, $input, $options);
    }
}
