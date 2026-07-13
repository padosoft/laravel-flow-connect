<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestController;
use ReflectionClass;

/**
 * Shared read/validate logic for `config('laravel-flow-connect.webhook')`,
 * used by BOTH {@see WebhookTriggerRegistrar} (at application boot, to
 * decide which slugs get a route registered — invalid entries are skipped
 * and logged there) and {@see WebhookRequestController}
 * (at request time, to resolve `flow`/`secret`/`mapper` for the matched
 * slug FRESH from config — deliberately NOT threaded through as route
 * defaults, unlike `slug` itself: a secret embedded in a route default
 * would be written verbatim into the host application's `route:cache`
 * build artifact, an unintended place to persist a credential).
 *
 * @internal
 */
final class WebhookTriggerConfig
{
    public const DEFAULT_ROUTE_PREFIX = 'laravel-flow-connect/webhook';

    public const DEFAULT_REPLAY_WINDOW_SECONDS = 300;

    /**
     * A slug becomes a literal, static path segment in the registered route
     * URI (never a `{placeholder}`) — restricting it to safe characters
     * prevents a stray `/`, `{`, or `}` in a config key from splitting the
     * path into unintended segments or colliding with Laravel's route
     * placeholder syntax.
     */
    private const SLUG_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * @param  array<array-key, mixed>  $entry  the raw `triggers[$slug]` config value
     * @return array{flow: string, secret: string, mapperClass: class-string<WebhookInputMapper>|null}|null null means invalid — caller decides how to report it
     */
    public static function validateEntry(int|string $slug, array $entry): ?array
    {
        if (! is_string($slug) || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        $flow = $entry['flow'] ?? null;
        $secret = $entry['secret'] ?? null;
        $mapperClass = $entry['mapper'] ?? null;

        if (! is_string($flow) || trim($flow) === '') {
            return null;
        }

        // trim(), not a bare '' check: a whitespace-only secret (" ") passes
        // an exact-'' comparison but is an effectively empty, low-entropy
        // credential — reject it the same way $flow's check already does.
        // The ORIGINAL untrimmed $secret is still what gets used for HMAC
        // (below) — this only rejects the all-whitespace case, it never
        // silently strips real whitespace from a legitimate secret.
        if (! is_string($secret) || trim($secret) === '') {
            return null;
        }

        if ($mapperClass !== null && ! self::isInstantiableMapper($mapperClass)) {
            return null;
        }

        /** @var class-string<WebhookInputMapper>|null $mapperClass */
        return ['flow' => $flow, 'secret' => $secret, 'mapperClass' => $mapperClass];
    }

    public static function isValidSlug(int|string $slug): bool
    {
        return is_string($slug) && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /**
     * Same over-permissive-`is_a()` pitfall as `EventTriggerRegistrar`: an
     * interface/abstract class name "is a" the target type too, passing a
     * naive check but failing at every actual instantiation attempt.
     */
    public static function isInstantiableMapper(mixed $mapperClass): bool
    {
        if (! is_string($mapperClass) || trim($mapperClass) === '' || ! class_exists($mapperClass)) {
            return false;
        }

        if (! is_a($mapperClass, WebhookInputMapper::class, true)) {
            return false;
        }

        return (new ReflectionClass($mapperClass))->isInstantiable();
    }

    public static function routePrefix(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return self::DEFAULT_ROUTE_PREFIX;
        }

        // Re-check emptiness AFTER stripping slashes: a configured value of
        // "/" or "///" passes the trim() !== '' check above but strips down
        // to '', which would silently produce a route like "/{slug}" instead
        // of falling back to the default prefix.
        $trimmed = trim($value, '/');

        return $trimmed !== '' ? $trimmed : self::DEFAULT_ROUTE_PREFIX;
    }

    public static function replayWindowSeconds(mixed $value): int
    {
        // Config values commonly arrive as numeric STRINGS (env() always
        // returns a string for a set variable, regardless of the config
        // file's declared default type) — accepting only is_int() silently
        // discards every real-world .env-configured value.
        if (is_int($value)) {
            return $value > 0 ? $value : self::DEFAULT_REPLAY_WINDOW_SECONDS;
        }

        // ctype_digit('') is false, so a non-empty check here is redundant.
        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : self::DEFAULT_REPLAY_WINDOW_SECONDS;
        }

        return self::DEFAULT_REPLAY_WINDOW_SECONDS;
    }
}
