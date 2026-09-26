<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Nodes\Support;

use stdClass;

/**
 * Reads a value out of decoded data with a deliberately tiny path language:
 *
 *   `$`            the whole value
 *   `$.a.b`        nested keys
 *   `$.items.0.id` list positions are plain keys
 *   `$.items.*.id` `*` maps over a list and returns the list of results
 *
 * There is no expression evaluation, no function call and no filter — a path
 * can only READ, so a mapping stored in a graph cannot execute anything.
 *
 * A path that does not exist yields {@see self::missing()}, which is distinct
 * from `null` (a present key holding null): `exists` and `default` depend on
 * telling them apart.
 *
 * @internal
 */
final class PathResolver
{
    private static ?stdClass $missing = null;

    /**
     * The "no such path" marker. Compare with {@see self::isMissing()}.
     */
    public static function missing(): stdClass
    {
        return self::$missing ??= new stdClass;
    }

    public static function isMissing(mixed $value): bool
    {
        return $value === self::missing();
    }

    public static function isPath(string $expression): bool
    {
        return preg_match('/^\$(?:\.[A-Za-z0-9_\-*]+)*$/', $expression) === 1;
    }

    /**
     * @throws \InvalidArgumentException when `$expression` is not a valid path
     */
    public static function resolve(mixed $data, string $expression): mixed
    {
        if (! self::isPath($expression)) {
            throw new \InvalidArgumentException('Not a valid path expression.');
        }

        $segments = $expression === '$' ? [] : explode('.', substr($expression, 2));

        return self::walk($data, $segments);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function walk(mixed $current, array $segments): mixed
    {
        if ($segments === []) {
            return $current;
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            if (! is_array($current)) {
                return self::missing();
            }

            $results = [];

            foreach ($current as $item) {
                $value = self::walk($item, $segments);

                if (! self::isMissing($value)) {
                    $results[] = $value;
                }
            }

            return $results;
        }

        if (! is_array($current) || ! array_key_exists($segment, $current)) {
            return self::missing();
        }

        return self::walk($current[$segment], $segments);
    }
}
