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
 * Reshapes data with a declarative mapping — no code, no expressions.
 *
 * `mapping` is `outputKey => spec`. A spec is one of:
 *
 *   "$.customer.email"                a path into `data` ("$" = the whole value)
 *   "Hello {{ $.customer.name }}"     a template (each `{{ }}` holds a path)
 *   "plain text" / 42 / true / null   a literal
 *   {"path": "$.qty", "default": 1, "cast": "int"}
 *   {"template": "...", "cast": "string"}
 *   {"value": [1, 2, 3]}              an explicit literal (needed for a list/object)
 *
 * `default` replaces a missing or null path; `cast` is one of int, float,
 * string, bool, json and leaves null untouched. The node is pure — it reads
 * `data` and returns a new array — so it runs in a dry run too, and its output
 * is `Derived`: untrusted in, untrusted out.
 *
 * @api
 */
#[FlowNode(type: 'connect.transform', category: 'connect', description: 'Reshapes data with a declarative path/template mapping.')]
final class TransformNode implements FlowNodeHandler
{
    private const SPEC_KEYS = ['path', 'template', 'value', 'default', 'cast'];

    private const CASTS = ['int', 'float', 'string', 'bool', 'json'];

    #[Input(type: PortType::Any, required: true)]
    public mixed $data;

    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: true)]
    public array $mapping;

    /** @var array<string, mixed> */
    #[Output(type: PortType::Json)]
    public array $result;

    public function execute(NodeContext $context): NodeResult
    {
        $data = $context->inputs['data'] ?? null;
        $mapping = $context->inputs['mapping'] ?? null;

        try {
            if (! is_array($mapping) || $mapping === [] || array_is_list($mapping)) {
                throw new MappingException('`mapping` must be a non-empty object of outputKey => spec.');
            }

            $result = [];

            foreach ($mapping as $key => $spec) {
                try {
                    $result[(string) $key] = $this->evaluate((string) $key, $spec, $data);
                } catch (\JsonException) {
                    // A value that cannot be JSON-encoded (invalid UTF-8, INF, a
                    // recursive structure) is a mapping problem, reported by key.
                    throw new MappingException(sprintf('Mapping [%s] produced a value that cannot be JSON-encoded.', (string) $key));
                }
            }
        } catch (MappingException $e) {
            return NodeResult::failed($e);
        }

        return NodeResult::success(['result' => $result]);
    }

    /**
     * @throws MappingException
     * @throws \JsonException
     */
    private function evaluate(string $key, mixed $spec, mixed $data): mixed
    {
        if (is_string($spec)) {
            if (str_starts_with($spec, '$')) {
                return $this->path($key, $spec, $data, null, false);
            }

            return str_contains($spec, '{{') ? $this->template($key, $spec, $data) : $spec;
        }

        if (! is_array($spec)) {
            return $spec; // int / float / bool / null literal
        }

        $unknown = array_diff(array_keys($spec), self::SPEC_KEYS);

        if ($unknown !== []) {
            throw new MappingException(sprintf('Mapping [%s] has an unknown key; allowed: %s.', $key, implode(', ', self::SPEC_KEYS)));
        }

        $sources = array_values(array_filter(['path', 'template', 'value'], static fn (string $k): bool => array_key_exists($k, $spec)));

        if (count($sources) !== 1) {
            throw new MappingException(sprintf('Mapping [%s] needs exactly one of `path`, `template` or `value`.', $key));
        }

        $value = match ($sources[0]) {
            'path' => $this->pathSpec($key, $spec, $data),
            'template' => $this->template($key, $this->string($key, 'template', $spec['template']), $data),
            default => $spec['value'],
        };

        return array_key_exists('cast', $spec) ? $this->cast($key, $value, $spec['cast']) : $value;
    }

    /**
     * @param  array<array-key, mixed>  $spec
     *
     * @throws MappingException
     * @throws \JsonException
     */
    private function pathSpec(string $key, array $spec, mixed $data): mixed
    {
        return $this->path($key, $this->string($key, 'path', $spec['path']), $data, $spec['default'] ?? null, array_key_exists('default', $spec));
    }

    /**
     * @throws MappingException
     */
    private function path(string $key, string $expression, mixed $data, mixed $default, bool $hasDefault): mixed
    {
        if (! PathResolver::isPath($expression)) {
            throw new MappingException(sprintf('Mapping [%s] has an invalid path; use "$" or "$.a.b".', $key));
        }

        $value = PathResolver::resolve($data, $expression);

        if (PathResolver::isMissing($value)) {
            return $hasDefault ? $default : null;
        }

        return $value === null && $hasDefault ? $default : $value;
    }

    /**
     * @throws MappingException
     * @throws \JsonException
     */
    private function template(string $key, string $template, mixed $data): string
    {
        // Every `{{` must close: an unterminated placeholder would otherwise be
        // left in the output verbatim and silently corrupt it.
        if (substr_count($template, '{{') !== preg_match_all('/\{\{\s*.*?\s*\}\}/s', $template)) {
            throw new MappingException(sprintf('Mapping [%s] has an unterminated `{{` placeholder.', $key));
        }

        return (string) preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/s', function (array $match) use ($key, $data): string {
            if (! PathResolver::isPath($match[1])) {
                throw new MappingException(sprintf('Mapping [%s] has a template placeholder that is not a path.', $key));
            }

            $value = PathResolver::resolve($data, $match[1]);

            return match (true) {
                PathResolver::isMissing($value), $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };
        }, $template);
    }

    /**
     * @throws MappingException
     * @throws \JsonException
     */
    private function cast(string $key, mixed $value, mixed $cast): mixed
    {
        if (! is_string($cast) || ! in_array($cast, self::CASTS, true)) {
            throw new MappingException(sprintf('Mapping [%s] has an unknown `cast`; use one of: %s.', $key, implode(', ', self::CASTS)));
        }

        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'int' => is_int($value) ? $value : (is_bool($value) ? (int) $value : $this->number($key, $value, $cast)),
            'float' => is_float($value) ? $value : (is_bool($value) ? (float) $value : $this->number($key, $value, $cast)),
            'string' => is_string($value) ? $value : (is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR))),
            'bool' => $this->boolean($key, $value),
            default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * @throws MappingException
     */
    private function number(string $key, mixed $value, string $cast): int|float
    {
        if (! is_numeric($value)) {
            throw new MappingException(sprintf('Mapping [%s] could not cast its value to %s.', $key, $cast));
        }

        $number = $cast === 'int' ? (int) $value : (float) $value;

        // "1e999" is numeric to PHP but overflows to INF, which cannot be stored
        // or JSON-encoded downstream: refuse it here, by key.
        if (is_float($number) && ! is_finite($number)) {
            throw new MappingException(sprintf('Mapping [%s] could not cast its value to a finite %s.', $key, $cast));
        }

        return $number;
    }

    /**
     * @throws MappingException
     */
    private function boolean(string $key, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $bool = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

        if ($bool === null) {
            throw new MappingException(sprintf('Mapping [%s] could not cast its value to bool.', $key));
        }

        return $bool;
    }

    /**
     * @throws MappingException
     */
    private function string(string $key, string $field, mixed $value): string
    {
        if (! is_string($value)) {
            throw new MappingException(sprintf('Mapping [%s] `%s` must be a string.', $key, $field));
        }

        return $value;
    }
}
