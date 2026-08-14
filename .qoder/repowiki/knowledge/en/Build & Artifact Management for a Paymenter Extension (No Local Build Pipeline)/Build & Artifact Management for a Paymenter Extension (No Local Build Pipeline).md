---
kind: build_system
name: Build & Artifact Management for a Paymenter Extension (No Local Build Pipeline)
category: build_system
scope:
    - '**'
source_files:
    - DynamicPterodactyl.php
    - phpunit.xml
    - tests/bootstrap.php
    - tests/TestCase.php
    - tests/LaravelTestCase.php
    - AGENTS.md
---

## What system/approach is used

This repository is a **nested Paymenter extension** (`Paymenter\Extensions\Others\DynamicPterodactyl`) and deliberately ships **no local build, packaging, or CI pipeline**. The project's own documentation states: "No extension-local Composer/npm build, lint command, or GitHub Actions workflow exists." The extension is consumed as source by the outer Paymenter application, which supplies autoloading, migrations, routes, Filament discovery, and the scheduler that runs its tasks.

The only executable artifact produced here is the PHP source tree itself; versioning lives in the `#[ExtensionMeta(...)]` attribute on `DynamicPterodactyl.php` (`version: '3.1.0'`).

## Key files and packages

- `DynamicPterodactyl.php` — Extension boot entry point registered by Paymenter; declares metadata (name, description, version), installs/rolls back migrations via `ExtensionHelper::runMigrations`, registers routes, policies, model observers, event listeners, and two named Laravel schedules (`dynamic-pterodactyl:cleanup-expired-reservations`, `dynamic-pterodactyl:check-capacity-alerts`).
- `phpunit.xml` — PHPUnit configuration defining `Unit` and `Feature` testsuites, source coverage restricted to `Services/`, and test-time environment overrides (`APP_ENV=testing`, `DB_CONNECTION=mariadb`, `DB_DATABASE=paymenter_test`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, mail/array, Pulse/Telescope disabled).
- `tests/bootstrap.php` — Bootstraps the outer Paymenter app's autoloader (`vendor/autoload.php` from either a relative path or `/var/www/paymenter/vendor/autoload.php`) and enforces DB isolation by aborting with exit code 2 if `DB_DATABASE` is not `paymenter_test` or `:memory:`.
- `tests/TestCase.php` and `tests/LaravelTestCase.php` — Base classes providing Mockery integration, mock helpers for pricing configs/nodes/resources, and an isolated `createApplication()` that boots the real Paymenter kernel from `bootstrap/app.php` (relative or `/var/www/paymenter/bootstrap/app.php`).
- `AGENTS.md` — Authoritative project guide that documents the absence of a local build step and the commands to run tests from the deployed checkout.

## Architecture and conventions

### Deployment model
The extension is installed into the host Paymenter application under `extensions/Others/DynamicPterodactyl`. Installation triggers `installed()`, which calls `ExtensionHelper::runMigrations('extensions/Others/DynamicPterodactyl/database/migrations')`; uninstallation rolls them back via `ExtensionHelper::rollbackMigrations(...)`. Routes are loaded imperatively from `boot()` with `require __DIR__ . '/routes/api.php'`, views are namespaced under `dynamic-pterodactyl` pointing at `resources/views`, and schedules/closures are registered inline — there are no local Artisan commands or jobs.

### Test harness as the closest thing to a build step
Because there is no `composer.json`, `Makefile`, Dockerfile, or CI YAML in this repo, the only reproducible execution surface is PHPUnit against the deployed Paymenter tree:

```bash
# From a deployed checkout
../../../vendor/bin/phpunit -c phpunit.xml
../../../vendor/bin/phpunit -c phpunit.xml --testsuite Unit
../../../vendor/bin/phpunit -c phpunit.xml --testsuite Feature
```

Or from the outer app root:
```bash
php artisan schedule:work
```

The test harness is intentionally self-contained: it boots the full Paymenter application, uses a dedicated `paymenter_test` MariaDB database, array cache/session/mail stores, sync queues, and mocks all Pterodactyl HTTP calls. Feature tests exercise the HTTP stack through the real route layer; unit tests mock services directly.

### Versioning
Version is declared once in the extension metadata attribute on `DynamicPterodactyl.php` (`version: '3.1.0'`). There is no separate `VERSION` file, git tag convention enforced in code, or changelog-driven release script in this repository.

### Conventions and constraints
- No local `composer.json`, `package.json`, Makefile, Dockerfile, or CI workflow is added to this directory — the author guidelines explicitly forbid it: "Do not add a local `composer.json` or frontend bundle; the outer app supplies autoloading and native sliders" (`AGENTS.md`, Anti-patterns section).
- Tests must never run against the production database: `tests/bootstrap.php` exits with status 2 when `DB_DATABASE` is anything other than `paymenter_test` or `:memory:`.
- Source coverage is scoped to `Services/` only (per `phpunit.xml` `<source>` include); controllers, models, listeners, and admin pages are not counted toward coverage.
- Schedules are named inline closures registered in `boot()`; there are no local Job or Command classes to compile or publish.
- Migrations live under `database/migrations/` and are discovered by the host app's migration runner via the explicit path passed to `ExtensionHelper::runMigrations`.
- The extension does not ship compiled assets — Blade views are served directly from `resources/views/admin/` and routed through the host app's view namespace.