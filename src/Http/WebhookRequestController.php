<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;
use Padosoft\LaravelFlowConnect\Exceptions\WebhookVerificationException;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTrigger;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTriggerRegistrar;
use Throwable;

/**
 * Handles every registered inbound webhook route. A CLASS (not a closure) —
 * {@see WebhookTriggerRegistrar} binds
 * the per-slug flow/secret/mapper/window as route DEFAULTS rather than
 * closure captures, so the routes it registers remain `route:cache`-able
 * (Laravel cannot serialize a closure into the route cache).
 *
 * Wraps its ENTIRE body in a single top-level try/catch: a request arrives
 * from an UNTRUSTED external caller inside a call stack this package does
 * not own (the host app's HTTP kernel), so an uncaught exception here must
 * never surface as a raw 500 with a leaked stack trace. {@see
 * WebhookVerificationException} (bad signature/replay/malformed JSON)
 * carries a caller-safe generic reason and the correct HTTP status; any
 * OTHER Throwable (a mapper throwing, `fire()` throwing) is logged with
 * full detail to the TRUSTED host log but answered with a generic message —
 * unlike `EventTriggerRegistrar`'s equivalent catch (where the log
 * recipient IS the message recipient), here the RESPONSE recipient is the
 * external, untrusted caller, so the exception message must never appear in
 * the HTTP response body.
 *
 * @internal
 */
final class WebhookRequestController
{
    public function __construct(
        private readonly WebhookTrigger $trigger,
        private readonly WebhookRequestVerifier $verifier,
        private readonly Container $container,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $route = $request->route();
        $slug = $this->stringParameter($route, 'slug');
        $flow = $this->stringParameter($route, 'flow');
        $secret = $this->stringParameter($route, 'secret');
        $mapperClassValue = $route->parameter('mapperClass');
        /** @var class-string<WebhookInputMapper>|null $mapperClass */
        $mapperClass = is_string($mapperClassValue) ? $mapperClassValue : null;
        $replayWindowSecondsValue = $route->parameter('replayWindowSeconds');
        $replayWindowSeconds = is_string($replayWindowSecondsValue) && ctype_digit($replayWindowSecondsValue)
            ? (int) $replayWindowSecondsValue
            : 0;

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

    private function stringParameter(Route $route, string $name): string
    {
        $value = $route->parameter($name);

        return is_string($value) ? $value : '';
    }
}
