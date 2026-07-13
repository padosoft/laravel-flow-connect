<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Http;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;
use Padosoft\LaravelFlowConnect\Exceptions\WebhookVerificationException;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTrigger;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTriggerConfig;
use Padosoft\LaravelFlowConnect\Triggers\WebhookTriggerRegistrar;
use Throwable;

/**
 * Handles every registered inbound webhook route. A CLASS (not a closure) —
 * {@see WebhookTriggerRegistrar} binds only the (non-sensitive) `slug` as a
 * route default, so the routes it registers remain `route:cache`-able
 * without ever writing a secret into that build artifact. `flow`/`secret`/
 * `mapper` are re-resolved HERE, fresh from config, via the SAME validation
 * {@see WebhookTriggerConfig::validateEntry()} the registrar ran at boot —
 * config does not change within a single request lifecycle, so this only
 * fails defensively (e.g. a route survived a stale `route:cache` after the
 * config changed).
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
        private readonly ConfigRepository $config,
        private readonly Container $container,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // $slug/$flow are read outside try/catch's scope but ASSIGNED inside
        // it — declared here so the catch block's log context can reference
        // whatever was resolved before a failure, without risking an
        // "undefined variable" if the failure happens before $flow is set.
        $slug = '';
        $flow = null;

        try {
            $slug = $this->stringParameter($request->route(), 'slug');
            $entry = $this->resolveEntry($slug);

            if ($entry === null) {
                // Defensive only (see class docblock): a route only ever
                // exists for a slug the registrar already validated at boot.
                Log::warning('laravel-flow-connect: webhook route matched a slug with no valid current config entry.', ['slug' => $slug]);

                return response()->json(['error' => 'not found'], 404);
            }

            ['flow' => $flow, 'secret' => $secret, 'mapperClass' => $mapperClass] = $entry;
            $replayWindowSeconds = WebhookTriggerConfig::replayWindowSeconds(
                $this->config->get('laravel-flow-connect.webhook.replay_window_seconds'),
            );

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
     * @return array{flow: string, secret: string, mapperClass: class-string<WebhookInputMapper>|null}|null
     */
    private function resolveEntry(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        /** @var array<array-key, mixed> $triggers */
        $triggers = (array) $this->config->get('laravel-flow-connect.webhook.triggers', []);
        $entry = $triggers[$slug] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        return WebhookTriggerConfig::validateEntry($slug, $entry);
    }

    /**
     * `Request::route()` can return null (no route resolver set yet — not
     * expected to ever be true when this controller runs, since Laravel
     * only invokes it AFTER routing succeeds, but a strictly-typed `Route`
     * parameter would let a TypeError escape BEFORE the try/catch below if
     * that assumption is ever wrong, undermining the "never leak an
     * uncaught exception to an untrusted caller" guarantee this class
     * exists for — same class of bug as the string-name-plus-payload event
     * dispatch case in EventTriggerRegistrar).
     */
    private function stringParameter(?Route $route, string $name): string
    {
        $value = $route?->parameter($name);

        return is_string($value) ? $value : '';
    }
}
