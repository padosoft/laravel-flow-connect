<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads `config('laravel-flow-connect.schedule_triggers')` and registers each
 * valid entry onto Laravel's {@see Schedule}. Validation is EAGER (at
 * registration time, i.e. application boot): a malformed `flow`/`cron` value
 * is logged as a warning and SKIPPED, never registered — one bad config entry
 * must never prevent the rest of the schedule (or the host application's
 * console kernel) from booting. A `fire()` failure at RUN time (e.g. the
 * target flow's own input validation rejects the configured static input) is
 * caught and logged the same way, never allowed to abort the scheduler's run
 * of the remaining registered events.
 *
 * @internal
 */
final class ScheduleTriggerRegistrar
{
    public function __construct(
        private readonly ScheduleTrigger $trigger,
    ) {}

    /**
     * @param  array<int, array{flow?: mixed, cron?: mixed, input?: mixed, timezone?: mixed}>  $entries
     */
    public function register(Schedule $schedule, array $entries): void
    {
        foreach ($entries as $index => $entry) {
            $flow = $entry['flow'] ?? null;
            $cron = $entry['cron'] ?? null;
            $input = $entry['input'] ?? [];
            $timezone = $entry['timezone'] ?? null;

            if (! is_string($flow) || trim($flow) === '') {
                $this->skip($index, 'the "flow" key must be a non-empty string');

                continue;
            }

            if (! is_string($cron) || ! CronExpression::isValidExpression($cron)) {
                $this->skip($index, sprintf('the "cron" value [%s] is not a valid cron expression', var_export($cron, true)));

                continue;
            }

            if (! is_array($input)) {
                $this->skip($index, 'the "input" key must be an array');

                continue;
            }

            if ($timezone !== null && ! is_string($timezone)) {
                $this->skip($index, 'the "timezone" key must be a string or null');

                continue;
            }

            $event = $schedule->call(function () use ($flow, $input, $index): void {
                try {
                    $this->trigger->fire($flow, $input);
                } catch (Throwable $e) {
                    Log::warning('laravel-flow-connect: schedule trigger fire() failed.', [
                        'flow' => $flow,
                        'config_index' => $index,
                        'exception' => $e::class,
                        'code' => $e->getCode(),
                    ]);
                }
            })->cron($cron);

            if ($timezone !== null) {
                $event->timezone($timezone);
            }
        }
    }

    private function skip(int|string $index, string $reason): void
    {
        Log::warning('laravel-flow-connect: schedule trigger config entry skipped.', [
            'config_index' => $index,
            'reason' => $reason,
        ]);
    }
}
