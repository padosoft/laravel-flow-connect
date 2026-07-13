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

];
