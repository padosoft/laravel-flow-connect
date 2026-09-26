# Laravel Flow Connect

> Declarative triggers for [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow): start a flow run from a cron schedule, a Laravel event, or a signed inbound webhook, with no glue code.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/laravel-flow-connect.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow-connect)
[![CI](https://img.shields.io/github/actions/workflow/status/padosoft/laravel-flow-connect/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/padosoft/laravel-flow-connect/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)

Part of the **Laravel Flow 2.0** suite, alongside [`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow) (the engine), [`padosoft/laravel-flow-ai`](https://github.com/padosoft/laravel-flow-ai) (the AI pack) and [`padosoft/laravel-flow-admin`](https://github.com/padosoft/laravel-flow-admin) (the operator console).

## Why

A workflow engine is only half the job. Something still has to *start* the runs: a cron tick, a domain event, a call from a partner system. Wiring that by hand means one scheduled closure, one listener and one signed controller per flow, each with its own error handling. It also means one more place where a bad config entry can take down the whole app on boot.

`laravel-flow-connect` replaces that glue with **one config file**:

| Trigger | Starts a run when… | Input comes from |
|---|---|---|
| `ScheduleTrigger` | a cron expression matches (Laravel's own scheduler) | the static `input` array in config |
| `EventTrigger` | a host Laravel event is dispatched | an optional `EventInputMapper`, or `[]` |
| `WebhookTrigger` | a signed `POST` arrives on its route | an optional `WebhookInputMapper`, or the JSON body verbatim |

All three implement core's shared `Padosoft\LaravelFlow\Contracts\FlowTrigger` (`@api`) contract and start the run through `Flow::dispatch()`. A third-party trigger source can implement the same contract.

**Failure isolation is the design rule.** A malformed config entry is *skipped and logged* at boot, never fatal. An event listener never lets an exception escape into the host's own `event()` call stack. A webhook never answers with an unhandled 500 or leaks internal error detail to the external caller.

## Requirements

- PHP `^8.3`
- Laravel `^13.0`
- [`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow) `^2.0`

## Installation

```bash
composer require padosoft/laravel-flow-connect
php artisan vendor:publish --tag=laravel-flow-connect-config
```

The service provider is auto-discovered. With the default (empty) config the package registers nothing, so every trigger is opt-in.

## Schedule trigger

```php
// config/laravel-flow-connect.php
'schedule_triggers' => [
    ['flow' => 'daily-report', 'cron' => '0 6 * * *', 'input' => ['range' => 'yesterday']],
    ['flow' => 'eu-digest',    'cron' => '30 7 * * 1-5', 'timezone' => 'Europe/Rome'],
],
```

Each entry is registered on Laravel's own scheduler, so it runs under the usual `php artisan schedule:run` and shows up in `schedule:list`. It hands the static `input` straight to `Flow::dispatch($flow, $input)`. `timezone` is an optional IANA identifier and defaults to `config('app.timezone')`.

- A malformed entry is **skipped and logged** at boot. This covers a non-array entry, an empty `flow`, an invalid `cron`, a non-array `input` or an unknown timezone.
- A `fire()` failure at run time (for example, the flow's own input validation rejects the configured input) is caught and logged. It never aborts the scheduler's run of the other events.
- The scheduler is resolved lazily (`afterResolving`), so shipping this package does not force the host's schedule to build on every Artisan command.

## Event trigger

```php
'event_triggers' => [
    ['event' => \App\Events\OrderPlaced::class, 'flow' => 'fulfill-order', 'mapper' => \App\Flow\OrderPlacedMapper::class],
],
```

```php
use Padosoft\LaravelFlowConnect\Contracts\EventInputMapper;

final class OrderPlacedMapper implements EventInputMapper
{
    public function map(object $event): array
    {
        return ['order_id' => $event->order->id, 'total' => $event->order->total];
    }
}
```

The mapper is container-resolved on every firing, so constructor injection works. Omit `mapper` to fire the flow with an empty input.

- If a mapper **cannot** build valid input, it should throw. The occurrence is then logged (with the exception message) and **no run is created**, so a run never starts from partial input.
- The listener validates the dispatch shape itself and swallows every failure. The host's `event(new OrderPlaced(...))` call never sees an exception from this package.
- An entry whose `event` class does not exist, or whose `mapper` is missing, not an `EventInputMapper`, or not instantiable (an interface or abstract class), is skipped and logged at boot.

## Inbound webhook trigger

Webhooks are **disabled by default**. Enable them and declare one entry per endpoint:

```php
'webhook' => [
    'enabled' => env('LARAVEL_FLOW_CONNECT_WEBHOOK_ENABLED', false),
    'route_prefix' => env('LARAVEL_FLOW_CONNECT_WEBHOOK_ROUTE_PREFIX', 'laravel-flow-connect/webhook'),
    'replay_window_seconds' => env('LARAVEL_FLOW_CONNECT_WEBHOOK_REPLAY_WINDOW_SECONDS', 300),
    'triggers' => [
        'order-webhook' => [
            'flow' => 'fulfill-order',
            'secret' => env('ORDER_WEBHOOK_SECRET'),
            'mapper' => \App\Flow\OrderWebhookMapper::class, // optional
        ],
    ],
],
```

This registers `POST /laravel-flow-connect/webhook/order-webhook`. Callers sign each request with the **same scheme core uses for its outbound webhooks** (`WebhookDeliveryClient`), so one laravel-flow app can trigger another out of the box:

```
X-Laravel-Flow-Signature: t={unix timestamp},v1={hex HMAC-SHA256 of "{timestamp}.{raw body}" keyed by secret}
```

```bash
TS=$(date +%s); BODY='{"order_id":42}'
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$ORDER_WEBHOOK_SECRET" -hex | sed 's/^.* //')
curl -X POST https://app.test/laravel-flow-connect/webhook/order-webhook \
  -H "Content-Type: application/json" -H "X-Laravel-Flow-Signature: t=$TS,v1=$SIG" -d "$BODY"
```

| Response | When |
|---|---|
| `202 {"status":"accepted"}` | signature valid, run dispatched |
| `401` | missing/malformed/invalid signature, timestamp outside the window, or a replay |
| `404` | no valid trigger entry for that slug |
| `422` | body is not a JSON object/array |
| `500 {"error":"internal error"}` | the mapper or `fire()` threw. Full detail goes to *your* log, never to the caller |

**Security properties**

- Signatures are compared in constant time. The timestamp must fall within `±replay_window_seconds`.
- **Replay protection**: each signature is consumed once via an atomic `Cache::add()`. It stays consumed until the end of its own signed timestamp window, so a future-skewed timestamp cannot slip through early. In a multi-server deployment, use a **shared** cache store (Redis, database, and so on).
- Secrets are **not** stored in route definitions, so `php artisan route:cache` never serialises them. The controller reads them from config per request.
- Slugs are validated (`[A-Za-z0-9_-]`), and an empty or whitespace-only secret disables the entry.

## Writing your own trigger source

Implement core's contract and call it from wherever your signal arrives (a queue consumer, an IMAP poller, an MQTT client…):

```php
use Padosoft\LaravelFlow\Contracts\FlowTrigger;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;

final class MqttTrigger implements FlowTrigger
{
    public function fire(string $flowName, array $input = [], ?FlowExecutionOptions $options = null): void
    {
        Flow::dispatch($flowName, $input, $options);
    }
}
```

## Stability

From v1.0.0 the package follows [Semantic Versioning](https://semver.org/). The SemVer-covered surface is the **config schema** (`config/laravel-flow-connect.php`), the **webhook wire format** (route shape, signature header, status codes) and the `@api` mapper contracts `Contracts\EventInputMapper` and `Contracts\WebhookInputMapper`. Classes marked `@internal` (the trigger, registrar, controller and verifier classes) may change in any release.

## Roadmap

The Flow 2.0 design also places a generic **HTTP/API node** and **utility nodes** (transform, condition, delay, batch) in this package. They are not part of v1.0 and will ship in a later minor release.

## Development

```bash
git clone https://github.com/padosoft/laravel-flow-connect
cd laravel-flow-connect
composer install
composer quality   # Pint + PHPStan (level 8) + PHPUnit Unit & Contract suites
```

Core is resolved from Packagist like any other dependency, so no sibling checkout is needed. On Windows, keep LF line endings (enforced by `.gitattributes`), because Pint checks them.

## License

Apache-2.0. See [LICENSE](LICENSE).
