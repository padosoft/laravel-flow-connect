<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;
use Padosoft\LaravelFlowConnect\Exceptions\WebhookVerificationException;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestVerifier;
use ReflectionClass;
use Throwable;

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
 * Every request handler wraps its ENTIRE body in a single top-level
 * try/catch: a request arrives from an UNTRUSTED external caller inside a
 * call stack this package does not own (the host app's HTTP kernel), so an
 * uncaught exception here must never surface as a raw 500 with a leaked
 * stack trace. {@see WebhookVerificationException} (bad signature/replay/
 * malformed JSON) carries a caller-safe generic reason and the correct
 * HTTP status; any OTHER Throwable (a mapper throwing, `fire()` throwing) is
 * logged with full detail to the TRUSTED host log but answered with a
 * generic message — unlike {@see EventTriggerRegistrar}'s equivalent catch,
 * where the log recipient IS the message recipient (the host app owns both
 * the listener and its own log), here the RESPONSE recipient is the
 * external, untrusted caller, so the exception message must never appear
 * in the HTTP response body.
 *
 * @internal
 */
final class WebhookTriggerRegistrar
{
    private const DEFAULT_ROUTE_PREFIX = 'laravel-flow-connect/webhook';

    private const DEFAULT_REPLAY_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly WebhookTrigger $trigger,
        private readonly WebhookRequestVerifier $verifier,
        private readonly Container $container,
    ) {}

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

            /** @var class-string<WebhookInputMapper>|null $mapperClass */
            $router->post(
                sprintf('%s/%s', $prefix, $slug),
                fn (Request $request): JsonResponse => $this->handle($request, $slug, $flow, $secret, $mapperClass, $replayWindowSeconds),
            );
        }
    }

    private function handle(Request $request, string $slug, string $flow, string $secret, ?string $mapperClass, int $replayWindowSeconds): JsonResponse
    {
        try {
            // header() can return array|string|null if the client sent the
            // header more than once — a repeated signature header is itself
            // a malformed request, so it correctly falls through to the
            // verifier's "missing/malformed" rejection rather than being
            // silently coerced.
            $signatureHeader = $request->header('X-Laravel-Flow-Signature');

            $payload = $this->verifier->verify(
                is_string($signatureHeader) ? $signatureHeader : null,
                $request->getContent(),
                $secret,
                $replayWindowSeconds,
                'laravel-flow-connect:webhook:'.$slug,
            );

            $input = $mapperClass !== null
                ? $this->container->make($mapperClass)->map($payload)
                : $payload;

            $this->trigger->fire($flow, $input);

            return response()->json(['status' => 'accepted'], 202);
        } catch (WebhookVerificationException $e) {
            // The message is a generic, pre-authored reason (never the
            // caller's own input echoed back) — safe to return verbatim.
            return response()->json(['error' => $e->getMessage()], $e->statusCode);
        } catch (Throwable $e) {
            // Host-controlled failure (mapper or fire() threw), but the
            // RESPONSE recipient is the external, untrusted webhook caller —
            // unlike EventTriggerRegistrar's log-only catch, the exception
            // detail must stay in the TRUSTED log and never reach the body.
            Log::warning('laravel-flow-connect: webhook trigger mapping/fire failed.', [
                'slug' => $slug,
                'flow' => $flow,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return response()->json(['error' => 'internal error'], 500);
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
        return is_string($value) && trim($value) !== '' ? trim($value, '/') : $default;
    }

    private function positiveIntOrDefault(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function skip(int|string $slug, string $reason): void
    {
        Log::warning('laravel-flow-connect: webhook trigger config entry skipped.', [
            'slug' => $slug,
            'reason' => $reason,
        ]);
    }
}
