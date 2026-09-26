<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowConnect\Exceptions\MappingException;
use Padosoft\LaravelFlowConnect\Nodes\Support\PathResolver;

/**
 * Takes ONE branch of a graph: evaluates `rules` against `value` and activates
 * either the `true` or the `false` output port with the value passed through.
 * The nodes behind the other port are skipped, never run (core's per-port
 * branching, `NodeResult::branch()`), so a `false` branch cannot fail on an
 * input it never received. Join branches with `flow.merge` or optional ports.
 *
 * `rules` is a list of `{path?, op, value?}`. `path` is a read-only path into
 * `value` (default `$`, the whole value — see {@see PathResolver}). `match` is
 * `all` (default: every rule must pass) or `any`. Operators:
 *
 *   eq neq gt gte lt lte        numeric when both sides are numeric (so "5"
 *                               equals 5 and "05" equals "5"), otherwise strict
 *   in not_in                   `value` must be a list
 *   contains                    substring of a string, or member of a list
 *   starts_with ends_with       strings only
 *   exists not_exists           path present and non-null / missing or null
 *   empty not_empty             null, "" or [] / anything else
 *
 * There is deliberately NO regular-expression operator (a stored pattern is a
 * denial-of-service vector) and no expression language. A comparison between
 * incompatible types is simply false, never an error.
 *
 * Pure, so it also evaluates in a dry run and shows the real path.
 *
 * @api
 */
#[FlowNode(type: 'connect.condition', category: 'connect', description: 'Routes the graph down its true or false branch.')]
final class ConditionNode implements FlowNodeHandler
{
    private const VALUE_OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'starts_with', 'ends_with'];

    private const BARE_OPS = ['exists', 'not_exists', 'empty', 'not_empty'];

    #[Input(type: PortType::Any, required: true)]
    public mixed $value;

    /** @var list<array<string, mixed>> */
    #[Input(type: PortType::Json, required: true)]
    public array $rules;

    #[Input(type: PortType::Text, required: false)]
    public string $match = 'all';

    // `key:` names the ports `true` / `false`; the properties are named
    // differently only to avoid reserved-looking identifiers.
    #[Output(type: PortType::Any, key: 'true')]
    public mixed $whenTrue;

    #[Output(type: PortType::Any, key: 'false')]
    public mixed $whenFalse;

    public function execute(NodeContext $context): NodeResult
    {
        $value = $context->inputs['value'] ?? null;
        $rules = $context->inputs['rules'] ?? null;
        $match = (string) ($context->inputs['match'] ?? 'all');

        try {
            if (! in_array($match, ['all', 'any'], true)) {
                throw new MappingException('`match` must be "all" or "any".');
            }

            if (! is_array($rules) || $rules === [] || ! array_is_list($rules)) {
                throw new MappingException('`rules` must be a non-empty list of {path?, op, value?}.');
            }

            // Validate EVERY rule up front so a malformed one cannot hide behind
            // a short-circuit (`any` stops at the first pass).
            foreach ($rules as $index => $rule) {
                $this->assertValid($index, $rule);
            }

            $passed = $match === 'all' ? $this->all($rules, $value) : $this->any($rules, $value);
        } catch (MappingException $e) {
            return NodeResult::failed($e);
        }

        $port = $passed ? 'true' : 'false';

        return NodeResult::branch([$port => $value], [$port]);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function all(array $rules, mixed $value): bool
    {
        foreach ($rules as $rule) {
            if (! $this->passes($rule, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function any(array $rules, mixed $value): bool
    {
        foreach ($rules as $rule) {
            if ($this->passes($rule, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws MappingException
     */
    private function assertValid(int $index, mixed $rule): void
    {
        $position = $index + 1;

        if (! is_array($rule) || ! isset($rule['op']) || ! is_string($rule['op'])) {
            throw new MappingException(sprintf('Rule #%d needs an `op`.', $position));
        }

        if (array_diff(array_keys($rule), ['path', 'op', 'value']) !== []) {
            throw new MappingException(sprintf('Rule #%d has an unknown key; allowed: path, op, value.', $position));
        }

        if (! in_array($rule['op'], [...self::VALUE_OPS, ...self::BARE_OPS], true)) {
            throw new MappingException(sprintf('Rule #%d has an unknown operator [%s].', $position, $rule['op']));
        }

        // Presence-aware: only an OMITTED path defaults to the whole value. An
        // explicit `"path": null` (or any non-string) is a malformed rule.
        if (array_key_exists('path', $rule) && (! is_string($rule['path']) || ! PathResolver::isPath($rule['path']))) {
            throw new MappingException(sprintf('Rule #%d has an invalid `path`; use "$" or "$.a.b".', $position));
        }

        if (in_array($rule['op'], self::VALUE_OPS, true) && ! array_key_exists('value', $rule)) {
            throw new MappingException(sprintf('Rule #%d operator [%s] needs a `value`.', $position, $rule['op']));
        }

        if (in_array($rule['op'], ['in', 'not_in'], true) && (! is_array($rule['value']) || ! array_is_list($rule['value']))) {
            throw new MappingException(sprintf('Rule #%d operator [%s] needs `value` to be a list.', $position, $rule['op']));
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function passes(array $rule, mixed $data): bool
    {
        $resolved = PathResolver::resolve($data, is_string($rule['path'] ?? null) ? $rule['path'] : '$');
        $missing = PathResolver::isMissing($resolved);
        $actual = $missing ? null : $resolved;
        $expected = $rule['value'] ?? null;

        return match ($rule['op']) {
            'exists' => $actual !== null,
            'not_exists' => $actual === null,
            'empty' => $actual === null || $actual === '' || $actual === [],
            'not_empty' => ! ($actual === null || $actual === '' || $actual === []),
            'eq' => $this->equals($actual, $expected),
            'neq' => ! $this->equals($actual, $expected),
            'gt' => $this->compare($actual, $expected) === 1,
            'gte' => in_array($this->compare($actual, $expected), [0, 1], true),
            'lt' => $this->compare($actual, $expected) === -1,
            'lte' => in_array($this->compare($actual, $expected), [0, -1], true),
            'in' => $this->member($actual, $expected),
            'not_in' => ! $this->member($actual, $expected),
            'contains' => $this->contains($actual, $expected),
            'starts_with' => is_string($actual) && is_string($expected) && $expected !== '' && str_starts_with($actual, $expected),
            'ends_with' => is_string($actual) && is_string($expected) && $expected !== '' && str_ends_with($actual, $expected),
            default => false,
        };
    }

    private function equals(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return is_array($a) && is_array($b) && $this->canonical($a) === $this->canonical($b);
        }

        if ($a === null || $b === null || is_bool($a) || is_bool($b)) {
            return $a === $b;
        }

        if ($this->numeric($a) && $this->numeric($b)) {
            return $this->compareNumbers($a, $b) === 0;
        }

        return $a === $b;
    }

    /**
     * @return int|null -1, 0, 1 — or null when the two values are not comparable
     */
    private function compare(mixed $a, mixed $b): ?int
    {
        if ($this->numeric($a) && $this->numeric($b)) {
            return $this->compareNumbers($a, $b);
        }

        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        return null;
    }

    /**
     * Two integers (an int, or a string of digits) are compared EXACTLY as
     * digit strings: converting both to float collapses distinct 64-bit ids
     * ("9007199254740992" vs "9007199254740993" are the same float), which would
     * route a graph down the wrong branch. Anything with a fraction or exponent
     * falls back to a float comparison.
     */
    private function compareNumbers(mixed $a, mixed $b): int
    {
        $left = $this->integerString($a);
        $right = $this->integerString($b);

        if ($left === null || $right === null) {
            return (float) $a <=> (float) $b;
        }

        $negativeLeft = str_starts_with($left, '-');
        $negativeRight = str_starts_with($right, '-');

        if ($negativeLeft !== $negativeRight) {
            return $negativeLeft ? -1 : 1;
        }

        $magnitude = strlen(ltrim($left, '-')) <=> strlen(ltrim($right, '-')) ?: strcmp(ltrim($left, '-'), ltrim($right, '-')) <=> 0;

        return $negativeLeft ? -$magnitude : $magnitude;
    }

    /**
     * The canonical digit string of an integer value (no sign for zero, no
     * leading zeros), or null when the value is not an integer.
     */
    private function integerString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value) || preg_match('/^([+-]?)(\d+)$/', $value, $m) !== 1) {
            return null;
        }

        $digits = ltrim($m[2], '0');

        return $digits === '' ? '0' : ($m[1] === '-' ? '-' : '').$digits;
    }

    private function numeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    private function member(mixed $needle, mixed $list): bool
    {
        foreach (is_array($list) ? $list : [] as $candidate) {
            if ($this->equals($needle, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_string($haystack)) {
            return is_string($needle) && $needle !== '' && str_contains($haystack, $needle);
        }

        return is_array($haystack) && array_is_list($haystack) && $this->member($needle, $haystack);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function canonical(array $value): string
    {
        return json_encode($this->sorted($value), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sorted($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
