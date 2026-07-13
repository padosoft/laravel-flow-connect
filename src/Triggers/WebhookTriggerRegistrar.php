<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestController;
use ReflectionClass;

/**
 * Reads `config('laravel-flow-connect.webhook')` and registers one POST
 * route per valid `triggers` entry. Validation is EAGER (at registration
 * time, i.e. application boot, mirroring {@see EventTriggerRegistrar}): a
 * malformed slug/flow/secret/mapper is logged as a warning and SKIPPED,
 * never routed — one bad config entry must never prevent the rest of the
 * registrations, or the host application's boot, from completing.
 *
 * `Router::post()` only stores a route definition; it forces no eager
 * resolution, so — like {@see EventTriggerRegistrar} and unlike the
 * schedule trigger's `afterResolving()` deferral — there is nothing to
 * defer and registration happens unconditionally whenever the webhook
 * feature is enabled, regardless of console/HTTP context (routes must
 * exist for `route:list`, route caching, AND actual HTTP handling alike).
 *
 * The route action is {@see WebhookRequestController} (a CLASS, not a
 * closure): Laravel's `route:cache` cannot serialize a closure action, so a
 * host application caching its routes would otherwise lose every webhook
 * route silently. Each entry's `flow`/`secret`/`mapper`/replay-window is
 * threaded through as route DEFAULTS (`Route::defaults()`) rather than
 * closure captures — defaults ARE cache-serializable, and the controller
 * reads them back via `$request->route()->parameter(...)`.
 *
 * @internal
 */
final class WebhookTriggerRegistrar
{
    private const DEFAULT_ROUTE_PREFIX = 'laravel-flow-connect/webhook';

    private const DEFAULT_REPLAY_WINDOW_SECONDS = 300;

    /**
     * @param  array<string, mixed>  $config  the raw `laravel-flow-connect.webhook` config array
     */
    public function register(Router $router, array $config): void
    {
        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        $prefix = $this->stringOrDefault($config['route_prefix'] ?? null, self::DEFAULT_ROUTE_PREFIX);
        $replayWindowSeconds = $this->positiveIntOrDefault($config['replay_window_seconds'] ?? null, self::DEFAULT_REPLAY_WINDOW_SECONDS);

        /** @var array<array-key, mixed> $triggers */
        $triggers = is_array($config['triggers'] ?? null) ? $config['triggers'] : [];

        foreach ($triggers as $slug => $entry) {
            if (! is_string($slug) || trim($slug) === '') {
                $this->skip($slug, 'the "triggers" array key must be a non-empty string route slug');

                continue;
            }

            if (! is_array($entry)) {
                $this->skip($slug, 'the config entry itself must be an array');

                continue;
            }

            $flow = $entry['flow'] ?? null;
            $secret = $entry['secret'] ?? null;
            $mapperClass = $entry['mapper'] ?? null;

            if (! is_string($flow) || trim($flow) === '') {
                $this->skip($slug, 'the "flow" key must be a non-empty string');

                continue;
            }

            if (! is_string($secret) || $secret === '') {
                $this->skip($slug, 'the "secret" key must be a non-empty string');

                continue;
            }

            if ($mapperClass !== null && ! $this->isInstantiableMapper($mapperClass)) {
                $this->skip($slug, sprintf('the "mapper" value must be an instantiable class implementing %s', WebhookInputMapper::class));

                continue;
            }

            $router->post(sprintf('%s/%s', $prefix, $slug), WebhookRequestController::class)
                ->defaults('slug', $slug)
                ->defaults('flow', $flow)
                ->defaults('secret', $secret)
                ->defaults('mapperClass', $mapperClass)
                // Stored as a STRING: Route::parameter()'s declared return
                // type is object|string|null (matching how URI-matched
                // segments always arrive as strings) — an int default would
                // work at runtime but fights that contract. The controller
                // parses it back.
                ->defaults('replayWindowSeconds', (string) $replayWindowSeconds);
        }
    }

    /**
     * Same over-permissive-`is_a()` pitfall as {@see EventTriggerRegistrar}:
     * an interface/abstract class name "is a" the target type too, passing
     * this check but failing at every actual instantiation attempt.
     */
    private function isInstantiableMapper(mixed $mapperClass): bool
    {
        if (! is_string($mapperClass) || trim($mapperClass) === '' || ! class_exists($mapperClass)) {
            return false;
        }

        if (! is_a($mapperClass, WebhookInputMapper::class, true)) {
            return false;
        }

        return (new ReflectionClass($mapperClass))->isInstantiable();
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        // Re-check emptiness AFTER stripping slashes: a configured value of
        // "/" or "///" passes the trim() !== '' check above but strips down
        // to '', which would silently produce a route like "/{slug}" instead
        // of falling back to the default prefix.
        $trimmed = trim($value, '/');

        return $trimmed !== '' ? $trimmed : $default;
    }

    private function positiveIntOrDefault(mixed $value, int $default): int
    {
        // Config values commonly arrive as numeric STRINGS (env() always
        // returns a string for a set variable, regardless of the config
        // file's declared default type) — accepting only is_int() silently
        // discarded every real-world .env-configured value.
        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }

        // ctype_digit('') is false, so a non-empty check here is redundant.
        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : $default;
        }

        return $default;
    }

    private function skip(int|string $slug, string $reason): void
    {
        Log::warning('laravel-flow-connect: webhook trigger config entry skipped.', [
            'slug' => $slug,
            'reason' => $reason,
        ]);
    }
}
