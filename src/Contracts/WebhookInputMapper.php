<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Contracts;

use Padosoft\LaravelFlow\Contracts\FlowTrigger;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTriggerRegistrar;

/**
 * Maps an inbound webhook's decoded JSON payload to the input array handed
 * to {@see FlowTrigger::fire()}. Container-resolved per request by
 * {@see WebhookTriggerRegistrar} — a mapper may declare constructor
 * dependencies like any other container-built class.
 *
 * A mapper that cannot produce valid input for a given payload should THROW
 * (any `Throwable`) rather than return a best-effort/partial array: the
 * registrar treats a thrown exception as "mapping failed", logs it (with
 * the exception detail — this log recipient is the trusted host
 * application, not the external caller), and responds to the webhook
 * caller with a generic error, never creating a flow run with malformed
 * input and never leaking the exception message back to the external
 * caller.
 *
 * Omitting a mapper (`mapper` config key left null) fires the flow with the
 * decoded payload verbatim as its input — the payload IS the input.
 *
 * @internal
 */
interface WebhookInputMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function map(array $payload): array;
}
