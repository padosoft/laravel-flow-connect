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

];
