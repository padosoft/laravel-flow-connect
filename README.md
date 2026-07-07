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

## Installation

```bash
composer require padosoft/laravel-flow-connect
```

## License

Apache-2.0. See [LICENSE](LICENSE).
