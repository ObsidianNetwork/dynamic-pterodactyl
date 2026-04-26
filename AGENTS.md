# extensions/Others/DynamicPterodactyl — Companion Extension

**Nested git repo** (branch `dynamic-slider`, fork). Paymenter `Other` extension that pairs with the built-in `extensions/Servers/Pterodactyl/` provisioner to add per-product resource sliders (RAM/CPU/disk), real-time availability, and 15-min reservations. Provisioning itself stays in the built-in extension — this one only tracks reservations, calculates pricing, and exposes admin UI.

## Canonical entry

- `DynamicPterodactyl.php` (class `Paymenter\Extensions\Others\DynamicPterodactyl\DynamicPterodactyl extends App\Classes\Extension\Extension`)
  - `boot()` — requires `routes/api.php`, adds view namespace `dynamic-pterodactyl`, wires 4 event listeners.
  - `installed()` / `uninstalled()` — run/rollback migrations via `ExtensionHelper::runMigrations(...)`.
  - `getConfig()` — declares `pterodactyl_url`, `pterodactyl_api_key` (password), `reservation_ttl` (default 15, `integer|min:5|max:60`).

## Structure

```text
DynamicPterodactyl/
├── DynamicPterodactyl.php       # entry class
├── Admin/{Pages,Resources}      # Filament 4 — 4 Pages + 2 Resources
├── Http/Controllers/Api/        # Availability, Pricing, Reservation controllers
├── Listeners/                   # CartItemCreated/Deleted, InvoicePaid, ServiceCreated
├── Models/                      # AlertConfig, AuditLog, ResourceReservation (3 only)
├── Services/                    # 7 services, 1355 LOC (business logic core)
├── database/migrations/         # 5 migrations, all `ptero_*` tables
├── resources/views/admin/       # Blade partials for Filament Pages
├── routes/api.php               # /extensions/dynamic-pterodactyl/* (required from boot())
├── tests/{Unit,LaravelTestCase.php,TestCase.php}
├── phpunit.xml                  # self-contained: DB_DATABASE=paymenter_test (Test Isolation Mandate dp-13), bootstraps ../../../vendor/autoload.php
├── skeleton/                    # INACTIVE scaffold (pre-implementation), see notes
├── CLAUDE.md                    # pre-implementation conventions (some stale, see notes)
├── README.md                    # v3.1 architecture spec (some stale paths)
├── 01-DATABASE.md .. 09-IMPLEMENTATION.md   # authoritative feature specs
└── CHANGELOG.md, DECISIONS.md, PROGRESS.md  # operational logs
```

## Where to look

| Task | Start at | Spec |
|---|---|---|
| Tables / model schema | `Models/*.php`, `database/migrations/` | `01-DATABASE.md` |
| Business logic | `Services/` (one class per concern) | `02-SERVICES.md` |
| REST endpoints | `Http/Controllers/Api/`, `routes/api.php` | `03-API.md` |
| Cart/invoice hooks | `Listeners/` + `boot()` wiring | `04-EVENTS.md` |
| Filament screens | `Admin/Pages/*`, `Admin/Resources/*` | `05-ADMIN-UI.md` |
| Customer sliders | native `dynamic_slider` config option in Paymenter core | `06-FRONTEND.md` |
| Pricing math | `Services/PricingCalculatorService.php` | `07-PRICING-MODELS.md` |
| Node scoring | `Services/NodeSelectionService.php` | `08-ALGORITHMS.md` |
| Settled design debates | — | `DECISIONS.md` |
| Current WIP / checkpoint | — | `PROGRESS.md` |

## Conventions

- **Table prefix**: `ptero_*` (reservations, pricing_configs [dropped in migration 5], audit_logs, alert_configs).
- **Units**: memory/disk stored in **MB** (display as GB); CPU stored as **%** (100 = 1 core, 400 = 4 cores); money `decimal(10,2)`.
- **JSON columns**: `snake_case` keys.
- **Service rule**: one class per concern in `Services/`; controllers stay thin and delegate.
- **Filament split**: `Pages/` for dashboards (Dashboard, SetupWizard, NodeMonitoring, AuditLogPage); `Resources/` for CRUD (AlertConfig, Reservation).
- **Tests**: run via this directory's own `phpunit.xml` — `cd` in first, then `vendor/bin/phpunit` (the parent repo's phpunit won't pick these up; parent's `phpunit.xml` only includes `tests/`).

## Anti-patterns (this extension)

- **Do not commit from the outer Paymenter repo** — this is a separate git checkout (`.git/` present). `cd` in, then commit.
- **Do not add composer.json here** — outer Paymenter's `composer.json` already maps `Paymenter\Extensions\` → `extensions/` for the whole tree.
- **Do not reimplement server provisioning** — that's the companion pattern's whole point. Delegate to `extensions/Servers/Pterodactyl/`.
- **Do not add pricing logic to this extension's admin** — pricing moved to Paymenter core (`DECISIONS.md` → "Extension focuses on reservations/availability only"). The `ptero_pricing_configs` table was dropped in migration `2025_01_01_000005`.
- **Do not edit `skeleton/`** — it's a stale 2025-11-28 pre-implementation scaffold (only `DynamicPterodactyl.php`, `Services/ResourceCalculationService.php`, `routes/api.php`), superseded by root files. Safe to delete; retained for historical reference only.
- **Do not cache Pterodactyl API responses** — real-time queries are a settled decision (`DECISIONS.md`). Rate budget is ~10/min against the 240/min panel limit.
- **Do not swap Filament v3 APIs in** — Paymenter uses Filament 4 (inherited constraint; v3 namespaces moved).

## Known stale references in sibling docs

- `CLAUDE.md:63` says extension location is `app/Extensions/Others/DynamicPterodactyl/` → actual is `extensions/Others/DynamicPterodactyl/` (Paymenter v1.4 moved the tree).
- `README.md` "File Structure" block lists `Filament/` and `Jobs/` → actual is `Admin/` (Paymenter v1.4 renamed `Filament/` → `Admin/`), and `Jobs/` does not exist yet (scheduled cleanup is a TODO at `DynamicPterodactyl.php:95`).
- When instructions conflict, trust the filesystem + this file over `README.md` / `CLAUDE.md`.

## Open TODOs (grep targets)

- `DynamicPterodactyl.php:95` — register scheduled cleanup job (`CleanupExpiredReservations` every minute).
- `Listeners/InvoicePaidListener.php:53` — marked `CRITICAL`: final availability re-verification before confirming reservation; `:73-76` admin-notify on shortfall.
- `Services/AlertService.php:100` — email notification path pending mail setup.
- `routes/api.php:35` — admin API endpoints stub.

## Enforceable rules (CodeRabbit reads these)

- FAIL when: Pterodactyl API responses are cached (using `Cache::put`, `Cache::remember`, or similar). Rationale: real-time queries are a settled design decision per DECISIONS.md; caching violates the availability contract.
- FAIL when: pricing logic is added to this extension's admin or services. Rationale: pricing moved to Paymenter core per DECISIONS.md; the `ptero_pricing_configs` table was dropped in migration 5.
- FAIL when: server provisioning logic (createServer, suspendServer, terminateServer) is added here. Rationale: this extension is reservation/availability only; provisioning delegates to `extensions/Servers/Pterodactyl/`.
- FAIL when: files under `skeleton/` are modified. Rationale: `skeleton/` is a stale 2025-11-28 pre-implementation scaffold retained for historical reference only; edit live root files instead.
- FAIL when: a commit is made from the outer Paymenter repo's working tree. Rationale: this is a separate git repo (`.git/` present); `cd` into the extension directory before committing.
