# Changelog

All notable changes to `padosoft/laravel-flow-connect` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From v1.0.0, SemVer covers the config schema, the inbound-webhook wire format and classes annotated `@api`; `@internal` classes may change in any release.

## [Unreleased]

## [1.1.0] — 2026-09-27

Graph nodes for [`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow). Requires core **`^2.6`** (per-port branching and engine-resumed timers).

### Added

- **`connect.http.request`** — calls an HTTP API through a **named connection**: base URL, auth (`none` / `bearer` / `basic` / `header`), static headers, timeouts, TLS verification, a streamed response-size cap and an optional idempotency header live in `laravel-flow-connect.http.connections`, so a stored graph never carries a credential. An **egress guard** allows a request only when its host equals the connection's host and is on `http.allowed_hosts` (empty = deny all), refuses hosts resolving to loopback / private / link-local / CGNAT / reserved addresses unless `allow_private_network`, never follows redirects and pins the approved address (`CURLOPT_RESOLVE`, best effort against DNS rebinding). `path` cannot leave the connection; a node cannot override `Authorization` / `Host` / `Cookie` / `Content-Length` or inject a header. `connection`, `method` and `path` are `requiresTrusted`; the response is `Untrusted`. Failure messages carry the connection name and status only. No implicit retries.
- **`connect.transform`** — declarative `mapping` of paths, templates, literals and casts (`int` / `float` / `string` / `bool` / `json`); no expression language, no code.
- **`connect.condition`** — evaluates `rules` (`eq neq gt gte lt lte in not_in contains starts_with ends_with exists not_exists empty not_empty`, `match` all/any) and takes one branch through core's `NodeResult::branch()`; the other branch is skipped, not run. No regex operator by design.
- **`connect.delay`** — waits `seconds` or `until` an ISO-8601 time using core's engine-resumed timers: a queued run pauses on a persisted timer with no worker sleeping; a synchronous run sleeps inline only up to core's cap. `delay.max_seconds` bounds the wait (default 30 days).
- **`connect.batch`** — splits a list into fixed-size batches (`batch.max_size`, default 1000).
- New `@api` exceptions: `HttpNodeException` (base), `InvalidHttpConnectionException`, `EgressDeniedException`, `HttpRequestFailedException` and `MappingException`; the `Nodes\*` classes are `@api`. `WebhookVerificationException` stays `@internal`.

### Changed

- Requires `padosoft/laravel-flow ^2.6` (was `^2.0`) and declares `psr/http-message`.

### Notes

- The condition node needs core's `flow_run_nodes.active_ports` migration and the delay node its `resume_at` migration (both published with `--tag=laravel-flow-migrations`); schedule `flow:resume-due-timers` every minute for delays.
- Nothing in v1.0 changed: the triggers, their config and the webhook wire format are untouched.

## [1.0.0] — 2026-09-26

First stable release: declarative trigger sources for [`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow) (requires core `^2.0`).

### Added

- **Schedule trigger**: `schedule_triggers` entries (`flow`, `cron`, `input`, optional IANA `timezone`) are registered on Laravel's own scheduler and dispatch the flow with the static input. Malformed entries are skipped and logged at boot. Run-time `fire()` failures are logged and never abort the rest of the schedule. The scheduler is resolved lazily.
- **Event trigger**: `event_triggers` entries (`event`, `flow`, optional `mapper`) listen for a host event and dispatch the flow with the input built by an `EventInputMapper`. A throwing mapper creates no run, and no exception ever escapes into the host's event-dispatch call stack.
- **Inbound signed webhook trigger** (opt-in): one `POST {route_prefix}/{slug}` route per entry. It verifies `X-Laravel-Flow-Signature: t=…,v1=…` (HMAC-SHA256 over `timestamp.body`, the same scheme as core's outbound webhooks) with a timestamp window and atomic cache-backed replay protection. Responses are `202`, `401`, `404`, `422` and a generic `500`. Secrets stay out of `route:cache`.
- `Contracts\EventInputMapper` and `Contracts\WebhookInputMapper` (`@api`). All triggers implement core's `Padosoft\LaravelFlow\Contracts\FlowTrigger`.
- CI on PHP 8.3 / 8.4 / 8.5 × Laravel 13: Pint, PHPStan level 8, PHPUnit Unit + Contract suites.

### Changed

- Core dependency moved from the `dev-task/v2d-realtime-triggers` development branch (via a local path repository) to the tagged Packagist release `padosoft/laravel-flow: ^2.0`. The path repository, the `minimum-stability: dev` override and CI's sibling checkout of core are removed, so a plain `composer require padosoft/laravel-flow-connect` now works.

## [0.0.1] — 2026-07-07

- Pre-alpha scaffold for Packagist registration.
