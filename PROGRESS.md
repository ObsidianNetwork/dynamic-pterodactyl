# PROGRESS.md

Active implementation tracking. **Claude: Update this as you work.**

---

## Current Status

**Phase**: PR #22 + PR #23 successor review and handoff
**Last Updated**: 2026-08-18
**Last Session**: Fully rebased PR #22 onto the reviewed PR #23 work, restored every deployment protection, validated the exact extension/Paymenter pair across the full CI matrix and live read-only API path, and reconciled the preserved Qoder/GitHub Wiki.

---

## Quick Resume

> **For Claude**: Read this + "Current Session State" to quickly understand where we are.

All dp-NN backlog items are implemented. The completed successor is staged on `agent/rebase-pr22-on-pr23`; original draft PR #22 and prerequisite draft PR #23 remain unchanged for history. The exact Paymenter companion is published as draft PR #24. Mutagen deployment remains paused until the successor and companion are reviewed, merged, and separately authorized for deployment.

The Qoder metadata and canonical Wiki source links pin the latest code-bearing
predecessor. The later publication commit is intentionally not self-pinned:
its Git object ID cannot be known until the content containing that ID has
already been committed.

---

## Completed

### Documentation (Nov 2025)
- [x] Initial design document (v1.0 - v3.1)
- [x] Split into 9 focused files
- [x] README.md - index and architecture overview
- [x] 01-DATABASE.md - schema and migrations
- [x] 02-SERVICES.md - all 6 services with full code
- [x] 03-API.md - endpoints and controllers
- [x] 04-EVENTS.md - Paymenter event handlers
- [x] 05-ADMIN-UI.md - Filament pages and resources
- [x] 06-FRONTEND.md - JavaScript sliders and CSS
- [x] 07-PRICING-MODELS.md - pricing logic and examples
- [x] 08-ALGORITHMS.md - node selection and concurrency
- [x] 09-IMPLEMENTATION.md - roadmap and testing
- [x] CLAUDE.md - conventions, quick reference, compaction protocol
- [x] DECISIONS.md - architectural rationale
- [x] PROGRESS.md - this file (with session state + debug context sections)
- [x] CHANGELOG.md - milestone tracking
- [x] skeleton/ — deleted (empty subdirs only; dp-01 PR #16)

---

## In Progress

*dp-19 shipped on `dynamic-slider` (direct commits; default branch).*

| Plan | Summary | Status | Date |
|---|---|---|---|
| dp-10 | Slider UX + a11y baseline | Shipped — squash `a7fd667d` (fork PR #3) | Apr 2026 |
| dp-11 | Authorization + surface reduction | Shipped — squash `b665d6d6` (fork PR #9) | Apr 2026 |
| dp-13 | feat(setup-wizard): atomicity + audit reliability + test isolation | 8239686 | 2026-04-23 |
| dp-01 | doc refresh README/AGENTS/CLAUDE + delete empty skeleton/ | Shipped — squash `e2034a485` (fork PR #16) | Apr 2026 |
| dp-14 | Reservation endpoint throttle restore (10/min) | Shipped — squash `5b13f774` (fork PR #17) | Apr 2026 |
| dp-17 | Alert escalation: persistent delivery log + failure event + admin UI | Shipped — squash `b7cdd54ba05fad4abae26adbea22ea4ba61dec4d` (fork PR #18) | Apr 2026 |
| dp-16 | Docs sync: align 03-API + 05-ADMIN-UI + 09-IMPLEMENTATION with post-dp-13 architecture | Shipped — squash `f5f88c7` (PR #19) | Apr 2026 |
| dp-18 | Capacity-fanout Performance: batch admin-view Pterodactyl reads | Shipped — squash `be4756f` (PR #20) | Apr 2026 |
| dp-19 | Wire customer-facing slider to reservation API (guest support) | Shipped — direct commits on `dynamic-slider` | Apr 2026 |
| dp-20 | Reservation lifecycle fixes (cart-clear race, confirm-return, cleanup cron, expires_at guard) | Shipped inside dp-19 commit `a861479` (no separate PR) | Apr 2026 |

---

## Current Session State

> **Claude**: Update this frequently during work, not just at session end.
> This survives compaction and helps resume quickly.

**Last checkpoint**: 2026-08-18
**Working on**: Exact-SHA review and successor draft PR handoff
**Status**: The full PR #22 rebase is complete with every PR #23 safety fix preserved. `NodeCapacityPolicy`, zero-as-unlimited rejection, API-key encryption/migration, strict inventory validation, sanitized errors, and the private SQLite test database guard are present. The cross-repository PHP 8.3/8.4 SQLite and MariaDB 11/12 matrix is configured and green at the last reviewed commit.
**Current file**: PROGRESS.md
**Next action**: Finish the fresh exact-SHA review, open the successor draft PR targeting `dynamic-slider`, and retain PR #22/#23 as superseded history until the replacement path is approved. Resume Mutagen only as a separately authorized deployment step after both repositories are merged and the migration checklist is complete.
**Blockers**: Production deployment remains intentionally paused. The extension successor must ship with Paymenter companion PR #24, database backups and both migration sets, configured per-node capacity policies, confirmed exclusive provisioning control, a successful real-panel staging lifecycle canary, and an approved deployment window.

### This Session's Changes:

1. Preserved the useful panel close-out and PR #23 changes, then rebased the complete PR #22 history onto them without rewriting either published draft branch.
2. Consolidated live panel access behind `PterodactylInventoryService`, with complete pagination accounting, integral resource validation, duplicate/orphan rejection, local `NodeCapacityPolicy`, and zero-as-unlimited fail-closed behavior.
3. Restored the Paymenter-owned service and upgrade lifecycle seams, API-key encryption migration, durable commitments, scheduler health checks, and cancellation/provisioning safeguards.
4. Replaced caller-selected SQLite test paths with the process-private `:temporary:` named-memory database workflow, including separate-process coverage.
5. Pinned the audited Paymenter companion, added the cross-repository PHP/SQLite/MariaDB matrix, pinned third-party workflow actions to immutable commits, and completed a read-only live Pterodactyl inventory smoke test.
6. Preserved all 42 migrated Qoder pages, marked superseded deep dives as historical, published the reconciled canonical architecture, and verified the standalone Wiki hierarchy, source links, and anchors.
7. Redacted credential-bearing webhook transport failures from application logs and alert delivery history, with persisted failure-path coverage.
8. Extended the webhook public-address boundary to reject deprecated IPv6 site-local space (`fec0::/10`) before transport pinning.

---

## dp-19 — Wire customer-facing slider to reservation API

> **Historical milestone.** This records the April 2026 implementation. The
> reconciled successor uses customer quote endpoints and Paymenter cart/service
> lifecycle seams instead of the retired public reservation endpoint.

**Status**: Shipped  
**PR**: committed directly on `dynamic-slider` (default branch; no PR base)  
**Date**: 2026-04-27

Closed the architectural gap where `dynamicSliderGroup` Alpine component never called the reservation API. Changes:
- `resources/js/dynamic-slider-group.js` (NEW): Alpine component that listens for `slider-change` events, debounces 500ms, POSTs to `/api/dynamic-pterodactyl/reservation`, stores token in Alpine state + sessionStorage.
- `resources/views/components/form/dynamic-slider.blade.php`: emit `slider-change` on init and user input.
- `themes/default/views/products/checkout.blade.php`: conditional `x-data="dynamicSliderGroup(...)"` on outer div; `@else x-data="{ error: null, status: '' }"` fallback for non-slider products.
- `themes/obsidian/views/products/checkout.blade.php`: same integration (file updated on disk; gitignored in outer repo).
- `app/Livewire/Products/Checkout.php`: `hasDynamicSliderOptions()` + `reservationLocationId` computed property.
- `app/Livewire/Cart.php`: confirmation hook in `checkout()` with `class_exists()` guard.
- Extension: `routes/api.php` — `auth` → `checkout` middleware; `StoreReservationRequest` — `cart_item_id` nullable; `ReservationController` — guest `user_id = null`.
- 3 new extension guest tests; 7 outer feature tests.
- CR: 2 findings fixed (Alpine fallback + obsidian sync).


## dp-18 — Capacity-fanout Performance: batch admin-view Pterodactyl reads

> **Historical milestone.** This records the original batching work. The
> successor centralizes strict paginated location, node, server, and allocation
> reads in `PterodactylInventoryService`.

**Status**: Shipped  
**PR**: #20  
**Merge commit**: be4756f  
**Branch**: `dynamic-slider`  
**Date**: 2026-04-27

Added `ResourceCalculationService::buildClusterSnapshot()` which fetches all nodes + servers in 1-2 Pterodactyl API calls (using `include=servers` pagination, with fallback to separate server index). Swapped three admin call-sites (Dashboard, NodeMonitoring, AdminCapacityController) from O(locations × nodes) per-request API walks to the batch path. Added 5 unit tests including a performance call-count assertion (≤4 calls regardless of node count). CR: directly APPROVED on first review.

<!-- 
Update this section:
- When starting a new task
- Every 15-20 minutes during long tasks  
- When stuck on something
- Before context gets full
- When switching between files/tasks
-->

---

## Historical Implementation Checklist

> **Archived reference.** The checklist below records the original phased
> implementation and intentionally retains names that were later retired. It is
> not a current source inventory or deployment plan. Use **Current Status** and
> **Current Session State** above for the active contract.

### Phase 1: Foundation (COMPLETE)
- [x] Create extension directory structure
- [x] Create DynamicPterodactyl.php main class
- [x] Migration: ptero_resource_reservations
- [x] Migration: ptero_pricing_configs
- [x] Migration: ptero_audit_logs
- [x] Migration: ptero_alert_configs
- [x] Model: ResourceReservation
- [x] Model: PricingConfig
- [x] Model: AuditLog
- [x] Model: AlertConfig
- [x] Service: ResourceCalculationService
- [x] Service: NodeSelectionService
- [x] Service: PricingCalculatorService
- [x] Service: AuditLogService
- [x] Service: ReservationService
- [x] Service: AlertService

### Phase 2: API & Controllers (COMPLETE)
- [x] Controller: AvailabilityController
- [x] Controller: PricingController
- [x] Controller: ReservationController
- [x] Wire up routes in routes/api.php

### Phase 3: Event Handlers (COMPLETE)
- [x] CartItemCreatedListener - creates reservation when item added to cart
- [x] CartItemDeletedListener - cancels reservation when item removed
- [x] InvoicePaidListener - confirms reservation on payment
- [x] ServiceCreatedListener - logs service creation for tracking
- [x] Event listener registration in DynamicPterodactyl.php boot()

### Phase 4: Admin UI (COMPLETE)
- [x] PricingConfigResource + 3 page classes (List, Create, Edit)
- [x] ReservationResource + 1 page class (List only, table-based)
- [x] AlertConfigResource + 3 page classes (List, Create, Edit)
- [x] Dashboard page + Blade view (connection status, stats, capacity)
- [x] NodeMonitoring page + Blade view (per-node capacity tables)
- [x] AuditLogPage + Blade view (table with filters)
- [x] Uses Admin/Resources/ and Admin/Pages/ (Paymenter auto-discovery)

### Phase 5: Frontend (COMPLETE)
- [x] Resource slider JavaScript components (noUiSlider)
- [x] Real-time pricing display with breakdown
- [x] Location availability indicators with dynamic max limits
- [x] CSS styling for sliders (including dark mode)
- [x] Head event hook registration in boot()

### Phase 6: Unit Testing (COMPLETE)
- [x] phpunit.xml - extension-local PHPUnit configuration
- [x] tests/TestCase.php - base test class with Mockery
- [x] PricingCalculatorServiceTest - 10 tests for all pricing models
- [x] NodeSelectionServiceTest - 9 tests for node selection algorithm
- [x] ReservationServiceTest - 7 tests for reservation lifecycle

See 09-IMPLEMENTATION.md for full phase breakdown.

---

## Current Deployment Blockers

Production deployment is intentionally paused pending approval and merge of the
extension successor and Paymenter companion PR #24, database backups and both
migration sets, configured per-node capacity policies, confirmed exclusive
provisioning control, a successful real-panel staging lifecycle canary, and an
approved deployment window.

<!-- 
Format for blockers:
### [BLOCKER] Short description
**Waiting on**: What's needed to unblock
**Impact**: What can't proceed until resolved
**Raised**: Date
-->

---

## Decisions Made This Session

*Track any new decisions or clarifications made during implementation*

### Decision: Repurpose PricingConfig as Setup Wizard
**Context**: PricingConfig stored slider ranges + pricing redundantly - native ConfigOption metadata now stores same data.
**Decided**: Convert PricingConfigResource to SetupWizard page that creates native dynamic_slider ConfigOptions
**Rationale**: No redundant storage, single source of truth, cleaner architecture
**Update DECISIONS.md**: Yes

### Decision: Remove ptero_pricing_configs table
**Context**: Wizard now creates ConfigOptions directly - no need for intermediate table.
**Decided**: Add migration to drop the table, delete PricingConfig model
**Rationale**: Eliminates duplication, simplifies data flow
**Update DECISIONS.md**: Yes

### Decision: Dashboard reads from ConfigOptions
**Context**: Dashboard was querying ptero_pricing_configs for "Active Pricing Configs" stat.
**Decided**: Query config_options for products with dynamic_slider type instead
**Rationale**: Data now lives in ConfigOptions, consistent with new architecture
**Update DECISIONS.md**: No (implementation detail)

<!--
Format:
### Decision: Short title
**Context**: Why this came up
**Decided**: What we chose
**Rationale**: Why
**Update DECISIONS.md**: Yes/No (if significant)
-->

---

## Notes for Next Session

*Anything the next Claude session should know*

- **Setup Wizard replaces PricingConfigResource**: Navigate to "Dynamic Pterodactyl > Setup Wizard" in admin
  - Select product, configure slider ranges + pricing model
  - Creates native `dynamic_slider` ConfigOptions directly
  - Shows warning if product already has slider options (can overwrite)
- **ptero_pricing_configs table REMOVED**: Run `php artisan migrate` to drop it
- **PricingConfig model DELETED**: All pricing now stored in ConfigOption metadata
- **Dashboard updated**: Shows "Products with Sliders" count (queries ConfigOptions)
- **PricingCalculatorService updated**: Reads from ConfigOptions, delegates to ConfigOption::calculateDynamicPrice()
- **Native `dynamic_slider` with Extended Pricing**: Paymenter core now supports:
  - Linear pricing (base + rate × value)
  - Tiered pricing (volume discounts at breakpoints)
  - Base+Addon pricing (included units + overage)
- **DynamicPterodactyl Simplified**: Extension now focuses ONLY on:
  - Reservation system (resource locking)
  - Pterodactyl availability checks
  - Node selection algorithm
  - Event listeners for cart/checkout flow
- Extension structure at: `extensions/Others/DynamicPterodactyl/`
- API endpoints ready at `/api/dynamic-pterodactyl/`
- Tests: Run with `cd extensions/Others/DynamicPterodactyl && ../../vendor/bin/phpunit`

---

## Debug Context

> **Claude**: If stuck on a problem, document it here so you don't lose the thread.

### RESOLVED: Slider Flickering/Disappearing (Round 10)
**Problem**: Sliders would appear briefly then disappear in an infinite loop
**Root cause**: Livewire's DOM morphing destroyed our dynamically-created sliders, then our reinit logic would recreate them, triggering another Livewire update, and so on.

**Solution applied**:
1. Added `Livewire.hook('morph.updating')` with `skip()` to PREVENT Livewire from morphing our slider elements
2. Updated slider creation to use `$wire.set()` with debouncing instead of dispatching change events
3. Removed the failing reinit/MutationObserver logic (no longer needed since morphing is prevented)

Key code in `head-scripts.blade.php`:
```javascript
Livewire.hook('morph.updating', ({ el, skip }) => {
    if (el.classList?.contains('dynamic-ptero-slider-wrapper') ||
        el.querySelector?.('.dynamic-ptero-slider-wrapper')) {
        skip();  // Tell Livewire to leave this element alone
    }
});
```

This matches how Paymenter's native slider in `configoption.blade.php` works using `wire:ignore`.

<!-- 
When debugging, record:
### Problem: [short description]
**Error**: [exact error message]
**Occurs when**: [steps to reproduce]
**Tried**:
- [attempt 1] → [result]
- [attempt 2] → [result]
**Current hypothesis**: [what you think is wrong]
**Next to try**: [next debugging step]
-->

---

## Session Log

| Date | What Happened |
| 2026-04-27 | **dp-18 shipped** - PR #20, squash commit `be4756f`. Added `ResourceCalculationService::buildClusterSnapshot()` with `include=servers` pagination and server-index fallback, swapped Dashboard/NodeMonitoring/AdminCapacityController to the batch path, and added 5 unit tests including the ≤4-call regression guard. |
| 2026-04-27 | **dp-16 shipped** - PR #19, squash commit `f5f88c7`. Rewrote 03-API, 05-ADMIN-UI, and 09-IMPLEMENTATION to match post-dp-13 architecture. Removed phantom `AdminController`, documented actual admin routes, and updated Filament surface (SetupWizard, NodeMonitoring, etc.). |
|------|---------------|
| 2025-11-29 | **PricingConfig → Setup Wizard** - Converted redundant PricingConfig system to Setup Wizard that creates native dynamic_slider ConfigOptions. Deleted PricingConfig model, PricingConfigResource (3 pages), added drop table migration. Updated Dashboard, PricingCalculatorService, PricingController to read from ConfigOptions. Rewrote tests for new architecture. |
| 2026-04-22 | **dp-06 shipped** - Squash commit `3b1f1da`, merged 2026-04-22. |
| 2026-04-22 | **dp-07 shipped** - PR #6, squash commit `2b5ed12`. Locked 5 decisions in DECISIONS.md. Rewrote README, 01-DATABASE, 03-API, 05-ADMIN-UI, 06-FRONTEND, 07-PRICING-MODELS, 09-IMPLEMENTATION, PROGRESS, CHANGELOG. Retired `released` enum via migration, dropped `base_plus_addon` from validator + ConfigOptionSetupService, moved `/nodes` route to admin group. 93 tests pass. |
| 2026-04-22 | **Backlog confirmed** - dp-08: reservation verification + idempotency. dp-09: extension pricing scaffolding cleanup (after dp-core-01). dp-10: slider UX + a11y. dp-11: authorization + surface reduction. dp-12: capacity alerts + observability. dp-13: SetupWizard atomicity + audit-log reliability + E2E test. dp-core-01: Paymenter fork pricing patches (prerequisite for dp-09). |
| 2026-04-22 | **dp-core-01 drafted** - Pricing patches for the Paymenter fork, queued post-dp-07. |
| 2026-04-23 | **dp-core-01 shipped** - Paymenter fork PR #2, squash commit `121df289`. Five core patches: server-side `DynamicSliderPricingRule` (numeric coercion guards, last-tier-only unlimited rule, empty-string `up_to` rejection, non-string model guard, negative `up_to` rejection); strict throw on unknown dynamic_slider models in `ConfigOption::calculateDynamicPrice`; shared `plans.dynamic_slider_base_price` separated from per-slider marginals (`paymenter:migrate-slider-base-price` artisan); server-side `upgradable=false` for dynamic_slider via Page mutators (defense-in-depth alongside UI hide); slider-aware `Service::calculatePrice()` with backfill-window protection (resolves `slider_value` first, falls back to migrated property; gates base price on at least one resolved value to prevent under/overcharge during rollout) plus `paymenter:backfill-slider-config-values` artisan. CodeRabbit 6 review rounds, all threads resolved, mergeable=CLEAN. 105 tests, 330 assertions, all green. Created `FORK-NOTES.md` at repo root. |
| 2026-04-23 | **dp-09 shipped** - Extension pricing scaffolding cleanup. PR #8, squash commit `54da97db`. Retired `PricingCalculatorService` (delegate `/pricing/calculate` to core via `Plan::dynamicSliderBasePrice()` + `ConfigOption::calculateDynamicPriceDelta()`), renamed slim reader to `SliderConfigReaderService`, dropped `ReservationService` pricing guard, deleted `PricingConfigValidator` (rewired `ConfigOptionSetupService` to use `App\Rules\DynamicSliderPricingRule` directly), updated docs (02-SERVICES, 03-API, 07-PRICING-MODELS, DECISIONS, CHANGELOG). Dynamic slider validation (only configured sliders required), 404 on unconfigured product, 410 for retired validate endpoint, no debug-info leak in prod 500. CodeRabbit 4 review rounds, all 5 threads resolved, CLEAN/MERGEABLE. |
| 2026-04-24 | **dp-12 re-shipped under correct PR author** - PR #12, squash commit `9c028c8`, supersedes #11. CodeRabbit gate satisfied after aged `@coderabbitai review` mention; 115 tests, 1 skipped. |
| 2026-04-27 | **dp-14 shipped** - PR #17, squash commit `5b13f774`. Restored the documented reservation route throttle (`throttle:10,1`), synced the API spec note/code snippet, and replaced the broken reservation throttle test with the unconfigured-product validation pattern so the isolated check passes without touching known test-infra failures. |
| 2025-11-29 | **Extended Pricing + Extension Simplification** - Added tiered/base_addon pricing models to native `dynamic_slider`. Simplified DynamicPterodactyl: removed custom frontend sliders (head-scripts.blade.php), updated all event listeners to read from native config_options with `metadata.resource_type`, fixed property access to use morphMany relationship. Extension now focuses only on reservations and availability. |
| 2025-11-29 | **Paymenter Core - dynamic_slider Type** - Implemented native `dynamic_slider` config option type in Paymenter core. Adds continuous value selection (e.g., memory 1GB-64GB) with price calculated as `value × rate`. Changes to 7 files: migration, model, admin UI, blade component, Checkout.php, CartItem.php, Cart.php. |
| 2025-11-29 | **Round 10 Slider Fix** - Used `Livewire.hook('morph.updating')` with `skip()` to prevent DOM morphing of slider elements. Removed failing reinit/MutationObserver logic. Updated slider to use `$wire.set()` with debouncing. |
| 2025-11-28 | **Phase 6 Complete** - Created unit tests (phpunit.xml, 3 test files, 26 tests) |
| 2025-11-28 | **Phase 5 Complete** - Created frontend resource sliders (noUiSlider), pricing display, head hook |
| 2025-11-28 | **Phase 4 Complete** - Created Filament admin UI (3 resources, 3 pages, 4 views) |
| 2025-11-28 | **Phase 3 Complete** - Created 4 event listeners, integrated checkout flow |
| 2025-11-28 | **Phase 2 Complete** - Created 3 API controllers, wired up routes |
| 2025-11-28 | **Phase 1 Complete** - Created all 6 services in Services/ directory |
| 2025-11-28 | Created 4 Eloquent models, main extension class, and routes |
| 2025-11-28 | Created 4 database migration files in database/migrations/ |
| 2025-11-28 | Added compaction protocol to CLAUDE.md, enhanced PROGRESS.md with Current Session State and Debug Context sections |
| 2025-11-28 | Added CHANGELOG.md and skeleton/ directory with starter files |
| 2025-11-28 | Created split documentation structure (9 files + CLAUDE.md + DECISIONS.md + PROGRESS.md) |
| 2025-11-27 | Design document v3.1 completed with admin UI |
| 2025-11-27 | Switched to Companion Extension pattern (v3.0) |
| 2025-11-27 | Analyzed PteroSync, decided on real-time API |

---

## How to Update This File

**When starting work**:
- Move item from "Up Next" to "In Progress"
- Update "Current Session State" with what you're doing

**During work** (every 15-20 min or when switching tasks):
- Update "Current Session State" checkpoint
- This is your insurance against compaction

**When completing work**:
- Move item from "In Progress" to "Completed"
- Check the box [x]
- Update "Last Updated" date
- Clear "Current Session State" or set to next task

**When blocked**:
- Add to "Current Deployment Blockers" section
- Be specific about what's needed
