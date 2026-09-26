# Changelog

All notable changes to `padosoft/laravel-flow-connect` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From v1.0.0, SemVer covers the config schema, the inbound-webhook wire format and classes annotated `@api`; `@internal` classes may change in any release.

## [Unreleased]

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
