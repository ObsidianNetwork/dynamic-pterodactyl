# TEST HARNESS GUIDE

## OVERVIEW

Isolated PHPUnit harness for the DynamicPterodactyl extension inside a real Paymenter app checkout or `/var/www/paymenter` container. It tests extension services, requests, listeners, routes, schedules, model observers, and audit side effects without adding an extension-local Composer setup.

Use the root `AGENTS.md` for executable PHPUnit commands and repo-wide policy. This guide only covers harness selection, isolation, fixtures, and current coverage shape.

## STRUCTURE

```text
tests/
├── bootstrap.php          # autoload fallback + hard DB guard + harness requires
├── TestCase.php           # raw PHPUnit + Mockery + lightweight fixture helpers
├── LaravelTestCase.php    # boots Paymenter app + slider/node fixture helpers
├── Unit/                  # services, requests, listeners, observers; not all pure units
└── Feature/               # HTTP routes, schedule boot, API authorization/shape
```

Largest files are `Unit/ReservationServiceTest.php` and `Unit/AlertServiceTest.php`; read local helpers before adding duplicates.

## WHERE TO LOOK

| Task | Start at | Notes |
|---|---|---|
| Harness boot / DB safety | `bootstrap.php`, `../phpunit.xml` | `paymenter_test` / `:memory:` guard is enforced before app boot |
| Lightweight service seam | `TestCase.php` | Mockery cleanup, `createPricingConfig()`, `createNodeData()`, `standardResources()` |
| App-backed test seam | `LaravelTestCase.php` | Paymenter kernel boot, `createConfigOption()`, slider defaults, node fixtures |
| Reservation state / audits | `Unit/ReservationServiceTest.php` | Mixes facade mocks and real DB assertions under transactions |
| Panel capacity HTTP fakes | `Unit/ResourceCalculationServiceTest.php` | `Http::preventStrayRequests()`, panel response fakes, reservation inserts |
| API route behavior | `Feature/*ApiTest.php` | Feature tests explicitly require `routes/api.php` in `setUp()` |
| Alert delivery/audit split | `Unit/AlertServiceTest.php` | Separate-process facade tests plus Laravel audit subclass |

## CONVENTIONS

- Choose `TestCase` only for tests that do not need the Paymenter application, Eloquent factories, database assertions, Laravel HTTP helpers, or container bindings.
- Choose `LaravelTestCase` for DB writes, factories, facades, route tests, schedules, request validation with container/redirector, policies, or app service binding.
- `Unit/` means extension concern level, not necessarily pure unit. DB-backed service/request tests still live there when they do not drive an HTTP route.
- `Feature/` means externally observable Laravel surface: HTTP endpoints, middleware/authz shape, route budgets, and scheduled extension boot.
- Any DB-writing test must use `Illuminate\Foundation\Testing\DatabaseTransactions`; this includes DB-backed `Unit/` tests and all current API feature tests.
- Feature API tests must `require __DIR__ . '/../../routes/api.php';` in `setUp()` because extension routes are loaded manually by `DynamicPterodactyl::boot()` at runtime.
- For schedule assertions, boot the extension in the test and inspect `app(Schedule::class)->events()`; no local Job/Command classes exist.
- The bootstrap refuses unsafe databases: `DB_DATABASE` must be `paymenter_test`, `:memory:`, or empty before Laravel boots.
- `phpunit.xml` fixes testing env, `MAIL_MAILER=array`, `SESSION_DRIVER=array`, `CACHE_STORE=array` with legacy `CACHE_DRIVER=file`, sync queue, and service-source warning restrictions.
- Use shared fixture helpers before creating local copies: `standardResources()`, `createNodeData()`, `createConfigOption()`, and per-file helpers such as configured products/cart items.
- Common fakes are `Mockery` service/container bindings, `Http::fake()` with `Http::preventStrayRequests()` for panel calls, `Notification::fake()`, `Event::fake()`, `Log::spy()`, `Config::set()`, and `Gate::policy()`.
- The `AlertServiceTest` top-level class is the exception: it extends raw `TestCase`, runs in separate processes with global state disabled, and sets a minimal facade application to isolate alias/facade mocking.
- `AlertServiceAuditTest` in the same file switches back to `LaravelTestCase` plus `DatabaseTransactions` when asserting real audit rows, notifications, and alert config persistence.
- Prefer observable outcomes: response JSON/status for feature tests, DB rows for reservation/audit state, sent HTTP count for panel calls, dispatched events/notifications for alert delivery.

## ANTI-PATTERNS

- Do not run DB-writing tests without `DatabaseTransactions`; leaked rows are harness bugs.
- Do not assume extension routes are auto-discovered in tests; register `routes/api.php` explicitly for API feature coverage.
- Do not put app/factory/facade tests on raw `TestCase` except the existing separate-process `AlertService` facade isolation seam.
- Do not claim `Unit/` files are all pure units; several intentionally boot Laravel and write DB rows.
- Do not duplicate root command blocks, generic PHPUnit advice, or project-wide anti-patterns here.
- Do not loosen the `paymenter_test` / `:memory:` bootstrap guard.
- Do not add live Pterodactyl network calls; fake panel HTTP explicitly and prevent strays when testing capacity reads.
- Do not expose or assert full reservation tokens in audit payloads; assert `token_prefix` behavior.
- Current direct coverage gaps to watch: full cart-listener database integration and `AuditLogService` retrieval methods.
