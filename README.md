# Laravel Flow Connect

> Connector nodes and triggers for [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow) — the Laravel-native workflow orchestrator for the agentic era.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/laravel-flow-connect.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow-connect)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)

## Status

🚧 **Under active development** — this package is part of the **Laravel Flow 2.0 program** and is not yet stable. APIs will change without notice until the first tagged minor release. Follow [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow) for the core engine and roadmap.

⚠️ **Not yet installable via a plain `composer require` in a host application.** This package requires `padosoft/laravel-flow`, which has no tagged release yet — and, until the Laravel Flow 2.0 program's Macro D gate closes, tracks core's `task/v2d-realtime-triggers` macro branch rather than `main` (that branch is where core's trigger contract, `Padosoft\LaravelFlow\Contracts\FlowTrigger`, currently lives; it merges to core's `main` at the Macro D gate). Composer only reads the `repositories` block from the ROOT package of an install — this repo's own `composer.json` path-repository entry (see [Development setup](#development-setup)) is honored when working ON this package, but is silently ignored by any application that installs `laravel-flow-connect` AS a dependency. A host app that wants to try this package pre-tag must add the SAME repository entry for `padosoft/laravel-flow` to its OWN `composer.json` — see [Installation](#installation) below for the exact block.

## What it will provide

- **HTTP/API node** — call REST endpoints as typed graph nodes (pluggable auth, retry/backoff, response→port mapping).
- **Utility nodes** — transform, condition, delay/timer, batch.
- **Triggers** — start flows from cron schedules, Laravel events, and signed inbound webhooks (HMAC, timestamp window), mirroring laravel-flow's signed outbox scheme.

## Requirements

- PHP `^8.3`
- Laravel `^13.0`
- `padosoft/laravel-flow` (the core engine this package plugs into)

## Installation

Once `padosoft/laravel-flow` has a tagged v2 release, a plain install will work:

```bash
composer require padosoft/laravel-flow-connect
```

**Until then**, `padosoft/laravel-flow: dev-task/v2d-realtime-triggers` is not resolvable by a fresh host app on its own (see the warning above) — a Composer stability flag on a DIRECT root requirement does NOT propagate to that package's own transitive dependencies, so simply requiring `padosoft/laravel-flow-connect:dev-main` is not enough by itself. The host app's OWN `composer.json` needs BOTH a repository entry for the core package's macro branch AND a stability setting that covers it:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/padosoft/laravel-flow"
        }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
        "padosoft/laravel-flow-connect": "dev-main"
    }
}
```

`"minimum-stability": "dev"` + `"prefer-stable": true` (the same pair this repo's own `composer.json` uses) is the reliable option — it covers `padosoft/laravel-flow`'s transitive dev-branch requirement without needing to also list that package explicitly in the host app's own `require`.

## Development setup

`padosoft/laravel-flow` has no tagged v2 release yet, so this package's `composer.json` resolves it via a local **path repository** pointing at `../padosoft-laravel-flow` — a sibling checkout of the core repo, one directory up from this one. Clone both repos side by side:

```
Ai/
├── laravel-flow-connect/       (this repo)
└── padosoft-laravel-flow/      (core — note: directory name differs from the package name)
```

This is a development-time convenience. Two stages of retargeting are expected before this stabilizes:

1. **Now → Macro D gate**: `composer.json` tracks core's `task/v2d-realtime-triggers` MACRO branch (`"padosoft/laravel-flow": "dev-task/v2d-realtime-triggers"`), not `main` — the trigger contract this package's D-PR3/D-PR4/D-PR5 implement against lives there until the Macro D gate merges it to core's `main`. CI mirrors this by checking out that exact ref (see `.github/workflows/ci.yml`).
2. **Macro D gate → core's first v2 tag**: retarget both `composer.json` and CI back to `main`/`dev-main`.
3. **After core's first v2 tag**: the path repository entry and the `dev-main` constraint are replaced with a real version constraint (e.g. `^2.0`) against the tagged Packagist release.

## License

Apache-2.0. See [LICENSE](LICENSE).
