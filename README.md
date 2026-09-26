# Laravel Flow Connect

> Triggers and nodes for [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow): start a flow run from a cron schedule, a Laravel event or a signed inbound webhook, and build flows from ready-made **HTTP, transform, condition, delay and batch** nodes — no glue code.

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

Once a run has started, it needs building blocks. The package registers five **graph nodes** with core's node registry (see [Nodes](#nodes)):

| Node | Does |
|---|---|
| `connect.http.request` | calls an HTTP API through a **named connection** — credentials stay in config, never in the graph, and an egress allow-list is enforced |
| `connect.transform` | reshapes data with a declarative path/template mapping (no code) |
| `connect.condition` | takes **one branch** of the graph; the other branch is skipped, not run |
| `connect.delay` | waits for a duration or until a time, without a worker sleeping |
| `connect.batch` | splits a list into fixed-size batches |

## Requirements

- PHP `^8.3`
- Laravel `^13.0`
- [`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow) `^2.6` (the condition and delay nodes build on its per-port branching and engine-resumed timers)

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

## Nodes

The nodes are registered automatically (the provider appends them to core's `laravel-flow.nodes.handlers`); list them with `php artisan flow:nodes`. Each one declares typed ports, so core validates a graph's wiring when it is published.

### `connect.http.request`

Calls an HTTP API through a **named connection**. The graph carries the connection *name*; the base URL, credentials and limits live in `config/laravel-flow-connect.php`, so a stored graph, a run row or a dashboard can never leak a secret.

```php
// config/laravel-flow-connect.php
'http' => [
    // Egress allow-list. EMPTY = DENY ALL: the node is inert until a host opts in.
    'allowed_hosts' => ['api.stripe.com', '*.internal.example.com'],
    'connections' => [
        'stripe' => [
            'base_url' => 'https://api.stripe.com/v1',
            'auth' => ['type' => 'bearer', 'token' => env('STRIPE_SECRET')], // none | bearer | basic | header
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 30,
            'connect_timeout' => 5,
            'verify' => true,
            'max_response_bytes' => 1048576,
            'idempotency_header' => 'Idempotency-Key',  // optional
            'allow_private_network' => false,           // true only for an internal service
        ],
    ],
],
```

```php
new GraphNode('charge', 'connect.http.request', [
    'connection' => 'stripe', 'method' => 'POST', 'path' => 'charges',
    'body' => ['amount' => 1200, 'currency' => 'eur'],
]);
```

| Port | Type | |
|---|---|---|
| `connection` | Text, required, **trusted** | connection name |
| `method` | Text, **trusted** | `GET` (default), `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` |
| `path` | Text, **trusted** | relative to the base URL (see below) |
| `query`, `headers` | Json | query parameters / extra request headers |
| `body` | Any | an array is sent as JSON, a string verbatim; ignored for `GET`/`HEAD` |
| `failOnError` | Bool (default `true`) | fail the node on a status of 400 or more |
| → `status`, `ok`, `body`, `headers` | Int, Bool, Any, Json — all **untrusted** | JSON bodies are decoded; `Set-Cookie` is dropped |

**Security model**

- **No connection credential in the graph.** The connection's auth token/password and its static headers stay in config; only the connection *name* travels in the graph. Failure messages carry the connection name and status, never a URL, header or response body. This covers the connection's own credentials only: `query`, `headers` and `body` are ordinary node inputs and are stored with the graph and run data like any other input, so never put a credential in them — use the connection's `auth` or static `headers` instead.
- **Egress guard.** A request goes out only if its host *equals* the connection's host **and** is on `allowed_hosts` (exact, or `*.example.com`). Unless a connection sets `allow_private_network`, hosts resolving to loopback, private, link-local (cloud metadata), CGNAT or reserved addresses — IPv4-mapped IPv6 included — are refused. Redirects are never followed. The approved address is pinned for the request (`CURLOPT_RESOLVE`), best effort against DNS rebinding.
- **`path` cannot leave the connection**: absolute URLs, `//host`, backslashes, whitespace and `..` are refused — as written and after percent-decoding (`%2e%2e`, `%252e%252e`, `%2f`, `%5c`). A node input cannot override `Authorization`, `Host`, `Cookie` or `Content-Length`, nor inject a header.
- **Taint analysis.** `connection`, `method` and `path` are declared `requiresTrusted`, so core refuses to *publish* a graph that feeds them from an untrusted source (a model completion, a fetched page). The response is `Untrusted`.
- **Bounded.** Responses are streamed and capped at `max_response_bytes`.
- **No implicit retries**: a non-idempotent call must not retry by itself. Opt in per node with core's `config['retry']`; with `idempotency_header` set, every attempt of one node carries the same key. A dry run sends nothing.

### `connect.transform`

Reshapes data with `mapping: outputKey => spec`. A spec is a **path** (`"$"`, `"$.customer.email"`, `"$.items.0.sku"`, `"$.items.*.sku"` for a list), a **template** (`"Hello {{ $.name }}"`), a literal, or an object `{path | template | value, default?, cast?}` with `cast` one of `int`, `float`, `string`, `bool`, `json`.

```php
new GraphNode('shape', 'connect.transform', ['mapping' => [
    'email'   => '$.customer.email',
    'greeting'=> 'Hello {{ $.customer.name }}',
    'qty'     => ['path' => '$.items.0.qty', 'cast' => 'int', 'default' => 1],
    'skus'    => '$.items.*.sku',
]]);
```

Ports: `data` (Any, required) and `mapping` (Json, required) in, `result` (Json) out. There is no expression language and no function call — a path can only *read*. A missing path is `null` unless a `default` is given; an invalid mapping fails the node naming the mapping key (never the data). Pure, so it runs in a dry run.

### `connect.condition`

Takes **one branch**. It evaluates `rules` against `value` and activates either the `true` or the `false` output port, passing the value through; the nodes behind the other port are **skipped, never run** (core's per-port branching), so the branch not taken cannot fail on an input it never received.

```php
new GraphNode('gate', 'connect.condition', [
    'rules' => [['path' => '$.total', 'op' => 'gte', 'value' => 1000], ['path' => '$.country', 'op' => 'in', 'value' => ['IT', 'DE']]],
    'match' => 'all', // or 'any'
]);
// wire  gate.true -> manualReview  and  gate.false -> autoApprove, then join with flow.merge
```

Operators: `eq neq gt gte lt lte` (numeric when both sides are numeric, otherwise strict), `in not_in`, `contains` (substring or list member), `starts_with ends_with`, `exists not_exists`, `empty not_empty`. An incompatible comparison is simply `false`. There is deliberately **no regex operator** (a stored pattern is a denial-of-service vector). Every rule is validated up front, so a malformed one cannot hide behind an `any` short-circuit.

**Join branches with `flow.merge` or optional ports:** a node that receives both a taken and a not-taken branch still runs, with the dead input absent, and fails `invalid_input` if that input is required. Requires core `^2.6` and its `active_ports` migration.

### `connect.delay`

Waits, then passes `in` through on `out`. Set **exactly one** of `seconds` (from now) or `until` (ISO-8601 with an offset, e.g. `2026-10-01T09:00:00+02:00`; a time already past continues immediately).

It uses core's engine-resumed timers: on a **queued** run the node is stored `paused` with a resume time and a delayed job completes it — *no worker sleeps*, so a wait of hours or days is fine. A **synchronous** run sleeps inline only up to core's `laravel-flow.executor.max_inline_delay_seconds` (5) and fails the node beyond that, telling you to run the graph queued. A dry run never waits. `delay.max_seconds` (default 30 days) caps a wait so a typo cannot park a run for years.

Requirements: core `^2.6`, its `resume_at` migration, and the safety net scheduled every minute (it resumes a lost job and covers queue drivers that cannot delay, including `sync`):

```php
// routes/console.php
Schedule::command('flow:resume-due-timers')->everyMinute()->withoutOverlapping();
```

### `connect.batch`

Splits `items` (a list) into batches of `size` (a positive integer, capped by `batch.max_size`, default 1000): `[1,2,3,4,5]` with size 2 gives `batches` `[[1,2],[3,4],[5]]` and `count` 3. The last batch may be shorter; an empty list gives none. Pair it with core's `flow.foreach` to process a batch per iteration. Pure, so it runs in a dry run.

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

From v1.0.0 the package follows [Semantic Versioning](https://semver.org/). The SemVer-covered surface is the **config schema** (`config/laravel-flow-connect.php`), the **webhook wire format** (route shape, signature header, status codes), the **node types and their port keys** (`connect.http.request`, `connect.transform`, `connect.condition`, `connect.delay`, `connect.batch`), and the `@api` classes: the mapper contracts `Contracts\EventInputMapper` and `Contracts\WebhookInputMapper`, the node classes, and the node exceptions `Exceptions\HttpNodeException`, `InvalidHttpConnectionException`, `EgressDeniedException`, `HttpRequestFailedException` and `MappingException`. Classes marked `@internal` may change in any release: the trigger, registrar, controller and verifier classes, `Exceptions\WebhookVerificationException` and `Http\Client\*`.

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
