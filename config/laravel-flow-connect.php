<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Schedule triggers
    |--------------------------------------------------------------------------
    |
    | Each entry starts a flow run on a cron schedule. `flow` is the target
    | flow's registered name, `cron` a standard 5-field cron expression,
    | `input` the static input array handed to the flow (already shaped for
    | its declared input — the trigger does not transform it further), and
    | `timezone` an optional IANA timezone string (defaults to the
    | application's own `config('app.timezone')` when omitted).
    |
    | An entry with a malformed `flow`/`cron` is SKIPPED (not registered) and
    | logged as a warning at boot — one bad entry never prevents the rest of
    | the schedule, or the host application's console kernel, from booting.
    |
    | Example:
    |
    | 'schedule_triggers' => [
    |     ['flow' => 'daily-report', 'cron' => '0 6 * * *', 'input' => ['range' => 'yesterday']],
    | ],
    |
    */

    'schedule_triggers' => [],

    /*
    |--------------------------------------------------------------------------
    | Event triggers
    |--------------------------------------------------------------------------
    |
    | Each entry starts a flow run when a host application event fires.
    | `event` is the event's fully-qualified class-string, `flow` the target
    | flow's registered name, and `mapper` an OPTIONAL fully-qualified
    | class-string implementing
    | \Padosoft\LaravelFlowConnect\Contracts\EventInputMapper, container-
    | resolved and invoked per firing to build the flow's input from the
    | event object. Omitting `mapper` fires the flow with an empty input
    | array (no mapping performed).
    |
    | An entry with a malformed `event`/`flow`/`mapper` is SKIPPED (not
    | registered) and logged as a warning at boot — one bad entry never
    | prevents the rest of the registrations, or the host application's
    | boot, from completing. A mapper that THROWS at run time is caught and
    | logged the same way: the listener never lets an exception escape into
    | the host application's own event-dispatch call stack, and no flow run
    | is created for that occurrence.
    |
    | Example:
    |
    | 'event_triggers' => [
    |     ['event' => \App\Events\OrderPlaced::class, 'flow' => 'fulfill-order', 'mapper' => \App\Flow\OrderPlacedMapper::class],
    | ],
    |
    */

    'event_triggers' => [],

    /*
    |--------------------------------------------------------------------------
    | Inbound webhook triggers
    |--------------------------------------------------------------------------
    |
    | Opt-in (disabled by default): when `enabled` is true, one POST route is
    | registered per entry in `triggers`, at `{route_prefix}/{array key}`.
    | Each request must carry an
    | `X-Laravel-Flow-Signature: t={unix timestamp},v1={hex hmac-sha256}`
    | header — the SAME scheme core's own outbound webhook delivery uses
    | (`Padosoft\LaravelFlow\WebhookDeliveryClient`) — computed over
    | "{timestamp}.{raw body}" keyed by the entry's `secret`. A request whose
    | signature is missing/invalid, whose timestamp falls outside
    | `replay_window_seconds`, or whose signature has already been consumed
    | within that window (replay) is rejected with 401 and never reaches the
    | target flow. `flow` is the target flow's registered name; `mapper` an
    | OPTIONAL fully-qualified class-string implementing
    | \Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper — omitting it
    | fires the flow with the decoded JSON payload verbatim as input.
    |
    | An entry with a malformed key/`flow`/`secret`/`mapper` is SKIPPED (not
    | routed) and logged as a warning at boot. A mapper that THROWS at
    | request time, or a `fire()` failure, is logged with full detail (to
    | this application's own log) but answered to the EXTERNAL caller with a
    | generic error — the failure detail never appears in the HTTP response.
    |
    | Example:
    |
    | 'webhook' => [
    |     'enabled' => true,
    |     'triggers' => [
    |         'order-webhook' => ['flow' => 'fulfill-order', 'secret' => env('ORDER_WEBHOOK_SECRET'), 'mapper' => \App\Flow\OrderWebhookMapper::class],
    |     ],
    | ],
    |
    */

    'webhook' => [
        'enabled' => env('LARAVEL_FLOW_CONNECT_WEBHOOK_ENABLED', false),
        'route_prefix' => env('LARAVEL_FLOW_CONNECT_WEBHOOK_ROUTE_PREFIX', 'laravel-flow-connect/webhook'),
        'replay_window_seconds' => env('LARAVEL_FLOW_CONNECT_WEBHOOK_REPLAY_WINDOW_SECONDS', 300),
        'triggers' => [],
    ],

];
