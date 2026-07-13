<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowConnect\Triggers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlowConnect\Contracts\WebhookInputMapper;
use Padosoft\LaravelFlowConnect\Http\WebhookRequestController;

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
 * closure): Laravel's `route:cache` cannot safely serialize a closure
 * action. Only the (non-sensitive) `slug` is threaded through as a route
 * DEFAULT (`Route::defaults()`) — `flow`/`secret`/`mapper` are DELIBERATELY
 * NOT: a route default is written verbatim into the host application's
 * `route:cache` build artifact, an unintended place to persist a
 * credential. The controller re-resolves those fresh from config via
 * {@see WebhookTriggerConfig::validateEntry()} — the SAME validation this
 * class runs at boot — at request time, using only the slug.
 *
 * @internal
 */
final class WebhookTriggerRegistrar
{
    /**
     * @param  array<string, mixed>  $config  the raw `laravel-flow-connect.webhook` config array
     */
    public function register(Router $router, array $config): void
    {
        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        $prefix = WebhookTriggerConfig::routePrefix($config['route_prefix'] ?? null);

        /** @var array<array-key, mixed> $triggers */
        $triggers = is_array($config['triggers'] ?? null) ? $config['triggers'] : [];

        foreach ($triggers as $slug => $entry) {
            if (! WebhookTriggerConfig::isValidSlug($slug)) {
                $this->skip($slug, 'the "triggers" array key must be a non-empty string matching [A-Za-z0-9_-]+ (it becomes a literal route path segment)');

                continue;
            }

            if (! is_array($entry)) {
                $this->skip($slug, 'the config entry itself must be an array');

                continue;
            }

            if (WebhookTriggerConfig::validateEntry($slug, $entry) === null) {
                $this->skip($slug, sprintf(
                    'the entry must declare a non-empty "flow" and "secret", and an optional "mapper" that is an instantiable class implementing %s',
                    WebhookInputMapper::class,
                ));

                continue;
            }

            $router->post(sprintf('%s/%s', $prefix, $slug), WebhookRequestController::class)
                ->defaults('slug', $slug);
        }
    }

    private function skip(int|string $slug, string $reason): void
    {
        Log::warning('laravel-flow-connect: webhook trigger config entry skipped.', [
            'slug' => $slug,
            'reason' => $reason,
        ]);
    }
}
