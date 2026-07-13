<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Contracts;

use Padosoft\LaravelFlow\Contracts\FlowTrigger;
use Padosoft\LaravelFlowConnect\Triggers\EventTriggerRegistrar;

/**
 * Maps a host application event object to the input array handed to
 * {@see FlowTrigger::fire()}. Container-
 * resolved per firing by {@see EventTriggerRegistrar}
 * — a mapper may declare constructor dependencies like any other
 * container-built class.
 *
 * A mapper that cannot produce valid input for a given event should THROW
 * (any `Throwable`) rather than return a best-effort/partial array: the
 * registrar treats a thrown exception as "mapping failed", logs it, and
 * skips firing the trigger for that event occurrence — never creating a
 * flow run with malformed input.
 *
 * @internal
 */
interface EventInputMapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(object $event): array;
}
