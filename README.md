# Laravel Flow Connect

> Connector nodes and triggers for [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow) — the Laravel-native workflow orchestrator for the agentic era.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/laravel-flow-connect.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow-connect)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)

## Status

🚧 **Under active development** — this package is part of the **Laravel Flow 2.0 program** and is not yet stable. APIs will change without notice until the first tagged minor release. Follow [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow) for the core engine and roadmap.

## What it will provide

- **HTTP/API node** — call REST endpoints as typed graph nodes (pluggable auth, retry/backoff, response→port mapping).
- **Utility nodes** — transform, condition, delay/timer, batch.
- **Triggers** — start flows from cron schedules, Laravel events, and signed inbound webhooks (HMAC, timestamp window), mirroring laravel-flow's signed outbox scheme.

## Requirements

- PHP `^8.3`
- Laravel `^13.0`
- `padosoft/laravel-flow` (the core engine this package plugs into)

## Installation

```bash
composer require padosoft/laravel-flow-connect
```

## Development setup

`padosoft/laravel-flow` has no tagged v2 release yet, so this package's `composer.json` resolves it via a local **path repository** pointing at `../padosoft-laravel-flow` — a sibling checkout of the core repo, one directory up from this one. Clone both repos side by side:

```
Ai/
├── laravel-flow-connect/       (this repo)
└── padosoft-laravel-flow/      (core — note: directory name differs from the package name)
```

This is a development-time convenience only. Once `padosoft/laravel-flow` cuts its first v2 tag, the path repository entry and the `"*"` version constraint in `composer.json` will be replaced with a real version constraint (e.g. `^2.0`) against the tagged Packagist release. CI mirrors this layout by checking out both repositories as true siblings (see `.github/workflows/ci.yml`).

## License

Apache-2.0. See [LICENSE](LICENSE).
