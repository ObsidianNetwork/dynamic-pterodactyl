# PROJECT KNOWLEDGE BASE

**Generated:** 2026-07-25 18:23 AEST
**Commit:** `66a840a`
**Branch:** `dynamic-slider`

## OVERVIEW

Nested Paymenter `Other` extension that complements the built-in Pterodactyl provisioner. It owns real-time capacity reads, server-owned reservations, lifecycle validation, and Filament administration; Paymenter core remains the slider-pricing authority and the built-in server extension remains the provisioning authority.

## STRUCTURE

```text
DynamicPterodactyl/
├── DynamicPterodactyl.php       # boot, install/uninstall, policy, listeners, schedules
├── Admin/                       # Filament 5 pages/resources; see Admin/AGENTS.md
├── Http/                        # aggregate customer and raw admin APIs
├── Listeners/                   # transactional cart lifecycle adapters
├── Services/                    # reservation, capacity, alert, setup, audit core
├── Models/                      # 4 ptero_* models plus AlertConfig observer
├── database/migrations/         # 10 migrations, including checkout identity and key normalization
├── resources/views/admin/       # 1:1 Blade views for standalone admin pages
├── routes/api.php               # required from boot(); /api/dynamic-pterodactyl/*
├── tests/                       # isolated harness; see tests/AGENTS.md
└── 01-DATABASE.md … 09-IMPLEMENTATION.md
```

## WHERE TO LOOK

| Task | Start at | Supporting context |
|---|---|---|
| Extension lifecycle / schedules | `DynamicPterodactyl.php` | cleanup every minute; alerts every five minutes |
| Reservation state changes | `Services/ReservationService.php` | `Listeners/`, `Models/ResourceReservation.php` |
| Capacity / node choice | `Services/ResourceCalculationService.php`, `Services/NodeSelectionService.php` | `08-ALGORITHMS.md` |
| Checkout/provisioning reconciliation | `Services/ReservationService.php`, companion Pterodactyl `createServer()` | `04-EVENTS.md` |
| Live API surface | `routes/api.php`, `Http/Controllers/Api/` | `03-API.md` |
| Core slider metadata / price preview | `Services/SliderConfigReaderService.php`, `Http/Controllers/Api/PricingController.php` | core `Plan` and `ConfigOption` methods own math |
| Admin pages and actions | `Admin/AGENTS.md` | `resources/views/admin/` |
| Tables / casts / lifecycle values | `Models/`, `database/migrations/` | `DECISIONS.md` before stale schema prose |
| Test harness and coverage | `tests/AGENTS.md`, `phpunit.xml` | `tests/bootstrap.php` enforces DB isolation |
| Current decisions / checkpoint | `DECISIONS.md`, `PROGRESS.md` | `CHANGELOG.md` is milestone history |

## CODE MAP

Caller counts are CodeGraph static counts; Laravel events, container resolution, policies, schedules, and Filament discovery add runtime edges.

| Symbol | Type | Location | Refs | Role |
|---|---|---|---:|---|
| `DynamicPterodactyl::boot()` | method | `DynamicPterodactyl.php` | runtime | route, policy, observer, listener, schedule root |
| `ResourceCalculationService` | class | `Services/ResourceCalculationService.php` | 41 | uncached panel reads and cluster capacity snapshots |
| `ReservationService` | class | `Services/ReservationService.php` | cross-layer | cart, login, checkout, and provisioning state machine |
| `ReservationConfigurationService` | class | `Services/ReservationConfigurationService.php` | cross-layer | canonical payload and fingerprint authority |
| `AlertService` | class | `Services/AlertService.php` | 14 | threshold checks, delivery, shortfall notifications |
| `NodeSelectionService` | class | `Services/NodeSelectionService.php` | 11 | best-fit scoring over current capacity |
| `SliderConfigReaderService` | class | `Services/SliderConfigReaderService.php` | 5 | native `dynamic_slider` metadata reader |
| `ConfigOptionSetupService` | class | `Services/ConfigOptionSetupService.php` | admin | transactional setup-wizard writes into core options |
| `ResourceReservation` | model | `Models/ResourceReservation.php` | cross-layer | shared API, policy, service, listener, admin record |
| `routes/api.php` | route entry | `routes/api.php` | runtime | customer, checkout, and admin route groups |

## CONVENTIONS

- Tables use `ptero_*`; memory/disk use MB, CPU uses percent, money uses `decimal(10,2)`, JSON keys use `snake_case`.
- Reservation lifecycle is `pending -> confirmed | expired | cancelled`; `released` is retired.
- One pending hold is keyed by `cart_item_id`; reservation writes use transactions, `lockForUpdate()`, and deadlock retry.
- Tokens are internal/admin-only and never appear in browser, cart, service, URL, or audit state.
- Controllers validate/authorize then delegate. Customer responses expose aggregate capacity; raw node data is admin-only.
- Route budgets are explicit: availability/pricing/admin `30/min`; there is no customer reservation route.
- Provisioning must call `beginProvisioning()` and `completeProvisioning()` around the external create request.
- CPU is never treated as hard node capacity without an external authoritative inventory.
- Treat current code plus `DECISIONS.md` as authoritative.
- Record live work in `PROGRESS.md`; record newly settled architecture in `DECISIONS.md`.

## ANTI-PATTERNS (THIS PROJECT)

- Do not add Pterodactyl server creation, suspension, termination, ports, or user provisioning here.
- Do not add extension-owned pricing models/tables; `ptero_pricing_configs` was deliberately dropped.
- Do not cache Pterodactyl responses or snapshots; availability is a real-time contract.
- Do not expose node-level capacity on customer routes.
- Do not add a local `composer.json` or frontend bundle; the outer app supplies autoloading and native sliders.
- Do not copy legacy Filament v3/v4 APIs into this Filament 5 codebase.
- Do not introduce `released`, `base_plus_addon`, full-token audit fields, or retired API endpoints.
- Do not commit from the outer Paymenter worktree; this directory is its own git checkout.

## UNIQUE STYLES

- Routes are loaded by `boot()` rather than extension-local framework discovery.
- Cart created/updated/deleting events adapt cart lifecycle into the reservation service; provisioning is a direct companion-core integration.
- Schedules are named inline closures in `boot()`; there are no local Job/Command classes.
- Filament resources perform actions through services; standalone pages own 1:1 namespaced Blade views.
- Pricing preview calls core `Plan::dynamicSliderBasePrice()` and `ConfigOption::calculateDynamicPriceDelta()` directly.

## COMMANDS

```bash
# From a deployed Paymenter/extensions/Others/DynamicPterodactyl checkout
../../../vendor/bin/phpunit -c phpunit.xml
../../../vendor/bin/phpunit -c phpunit.xml --testsuite Unit
../../../vendor/bin/phpunit -c phpunit.xml --testsuite Feature

# From the outer Paymenter application root
php artisan schedule:work
```

No extension-local Composer/npm build, lint command, or GitHub Actions workflow exists. This `.tools` mirror has no vendor tree; run PHPUnit in the deployed Paymenter tree or its `/var/www/paymenter` container.

## NOTES

- `phpunit.xml` fixes `DB_DATABASE=paymenter_test`; `tests/bootstrap.php` aborts on any non-test database.
- Cleanup, capacity alert email/webhook delivery, shortfall email notifications, delivery logs, and admin API routes are implemented; older TODOs claiming otherwise are stale.
- PR/CodeRabbit identity, quiet-period, and verifier commands remain documented in `CLAUDE.md`.
- PHP LSP is not installed; the code map uses CodeGraph plus current source inspection.

## ENFORCEABLE RULES (CODERABBIT READS THESE)

- FAIL when Pterodactyl API responses are cached. Real-time availability is settled in `DECISIONS.md`.
- FAIL when pricing logic or storage is added to this extension. Paymenter core owns `dynamic_slider` pricing.
- FAIL when provisioning methods such as `createServer`, `suspendServer`, or `terminateServer` are added here.
- FAIL when customer endpoints return raw node-level capacity.
- FAIL when audit JSON stores a complete reservation token rather than a redacted prefix.
- FAIL when a commit is made from the outer Paymenter working tree instead of this nested repository.
