<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Nodes;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\Exceptions\MappingException;

/**
 * Waits, then hands `in` through on `out`. Set EXACTLY ONE of:
 *
 *   `seconds`  wait this long from now (0 = continue immediately)
 *   `until`    an ISO-8601 timestamp with an offset (`2026-10-01T09:00:00+02:00`
 *              or `...Z`); a time already past continues immediately
 *
 * It uses core's engine-resumed timer (`NodeResult::pausedUntil()`): on a QUEUED
 * run the node is stored `paused` with a resume time and a delayed job completes
 * it — no worker sleeps — so a delay of hours or days is fine. A SYNCHRONOUS run
 * sleeps inline only up to `laravel-flow.executor.max_inline_delay_seconds` and
 * fails the node beyond that, telling the caller to run the graph queued. A dry
 * run never waits.
 *
 * Requires core's timer migration, and `php artisan flow:resume-due-timers`
 * scheduled every minute as the safety net (see the README). The longest wait is
 * `laravel-flow-connect.delay.max_seconds` (default 30 days), so a typo cannot
 * park a run for years.
 *
 * @api
 */
#[FlowNode(type: 'connect.delay', category: 'connect', description: 'Waits for a duration or until a time, then continues.')]
final class DelayNode implements FlowNodeHandler
{
    public const DEFAULT_MAX_SECONDS = 2_592_000;

    #[Input(type: PortType::Any, required: false)]
    public mixed $in = null;

    #[Input(type: PortType::Int, required: false)]
    public int $seconds = 0;

    #[Input(type: PortType::Text, required: false)]
    public string $until = '';

    #[Output(type: PortType::Any)]
    public mixed $out;

    public function __construct(private readonly ConfigRepository $config) {}

    public function execute(NodeContext $context): NodeResult
    {
        try {
            $resumeAt = $this->resumeAt($context->inputs);
        } catch (MappingException $e) {
            return NodeResult::failed($e);
        }

        return NodeResult::pausedUntil($resumeAt, ['out' => $context->inputs['in'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $inputs
     *
     * @throws MappingException
     */
    private function resumeAt(array $inputs): DateTimeImmutable
    {
        $hasSeconds = array_key_exists('seconds', $inputs) && $inputs['seconds'] !== null;
        $hasUntil = array_key_exists('until', $inputs) && $inputs['until'] !== null && $inputs['until'] !== '';

        if ($hasSeconds === $hasUntil) {
            throw new MappingException('Set exactly one of `seconds` or `until`.');
        }

        $now = Date::now()->toDateTimeImmutable();
        $max = $this->maxSeconds();

        if ($hasSeconds) {
            $seconds = $inputs['seconds'];

            if (! is_int($seconds) || $seconds < 0) {
                throw new MappingException('`seconds` must be a non-negative integer.');
            }

            if ($seconds > $max) {
                throw new MappingException(sprintf('`seconds` exceeds the %d second limit (laravel-flow-connect.delay.max_seconds).', $max));
            }

            return $now->modify(sprintf('+%d seconds', $seconds));
        }

        $until = $this->parse($inputs['until']);

        if ($until->getTimestamp() - $now->getTimestamp() > $max) {
            throw new MappingException(sprintf('`until` is more than %d seconds away (laravel-flow-connect.delay.max_seconds).', $max));
        }

        return $until;
    }

    /**
     * @throws MappingException
     */
    private function parse(mixed $until): DateTimeImmutable
    {
        if (is_string($until) && $this->wellFormed($until)) {
            foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'] as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, $until);

                if ($parsed !== false && DateTimeImmutable::getLastErrors() === false) {
                    return $parsed;
                }
            }
        }

        throw new MappingException('`until` must be an ISO-8601 timestamp with an offset, e.g. 2026-10-01T09:00:00+02:00 or 2026-10-01T07:00:00Z.');
    }

    /**
     * PHP's parser is lenient: it accepts an offset like `+99:99` and calendar
     * overflow without reporting errors, and a past time is allowed here, so a
     * typo could complete the delay immediately. Validate every component
     * ourselves instead of trusting the parser's warnings.
     */
    private function wellFormed(string $until): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-](\d{2}):(\d{2}))$/', $until, $m) !== 1) {
            return false;
        }

        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', array_slice($m, 1, 6));

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return false;
        }

        // A numeric offset must be a real one: hours 00-14, minutes 00-59.
        $offsetHours = $m[7] ?? '';
        $offsetMinutes = $m[8] ?? '';

        return $offsetHours === '' || ((int) $offsetHours <= 14 && (int) $offsetMinutes <= 59);
    }

    private function maxSeconds(): int
    {
        $max = $this->config->get('laravel-flow-connect.delay.max_seconds', self::DEFAULT_MAX_SECONDS);

        return is_numeric($max) && (int) $max >= 1 ? (int) $max : self::DEFAULT_MAX_SECONDS;
    }
}
