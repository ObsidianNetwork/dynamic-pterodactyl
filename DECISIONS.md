# DECISIONS.md

Architectural decisions with rationale. Check here before re-debating a settled choice.

---

## Architecture

### Companion Extension vs. Standalone
**Decision**: Companion Extension pattern  
**Date**: November 2025  
**Status**: Final

**Context**: Need to add dynamic sliders to Pterodactyl products. Two options:
1. Standalone extension that handles everything
2. Companion that enhances the built-in Pterodactyl extension

**Rationale**:
- Built-in extension has ~300 lines of server provisioning code
- Reimplementing = bugs + missing upstream fixes
- Companion pattern: we handle pricing/sliders, built-in handles server creation
- If our extension fails, graceful degradation (product still works, just no sliders)

**Trade-off**: Dependent on built-in extension structure. If Paymenter changes it significantly, we need to adapt.

---

### Real-Time API vs. Caching
**Decision**: Real-time Pterodactyl API calls, no caching  
**Date**: November 2025  
**Status**: Final

**Context**: Need node availability data for slider limits.

**Rationale**:
- PteroSync (WHMCS module) uses real-time API successfully in production
- Caching introduces staleness → overselling risk
- Cache invalidation is complex (server created outside our system, admin changes, etc.)
- Pterodactyl allows 240 requests/minute; we use ~5-10 per checkout
- 200ms API call is acceptable for checkout flow

**Trade-off**: Slightly slower than cached reads. Acceptable.

**NOT reconsidering unless**: Pterodactyl rate-limits us or latency exceeds 500ms consistently.

---

### Database vs. Session for Reservations
**Decision**: Database with pessimistic locking  
**Date**: November 2025  
**Status**: Final

**Context**: Need to hold resources during checkout to prevent overselling.

**Rationale**:
- Sessions don't survive across requests reliably
- Database allows admin visibility into pending reservations
- Pessimistic locking (SELECT FOR UPDATE) prevents race conditions
- Transaction retry (5 attempts) handles deadlocks

**Trade-off**: More complex than session storage. Worth it for correctness.

---

## Pricing

### Three Models Only → Moved to Paymenter Core
**Decision**: Linear, Tiered, Base+Addon (now in Paymenter core)
**Date**: November 2025 (revised)
**Status**: Superseded — implemented in core

**Original Decision**: Three pricing models in DynamicPterodactyl extension

**Revision (Nov 2025)**: Moved all three pricing models to Paymenter core's native `dynamic_slider` config option type.

**Rationale for move**:
- Linear: Simple per-unit ($0.50/GB)
- Tiered: Volume discounts (cloud-style)
- Base+Addon: Package upsells ($15 includes X, extras cost Y)
- These cover ~95% of hosting pricing needs
- **Now in core**: Single source of truth, no JS injection, works for any product type (not just Pterodactyl)

**Trade-off**: Extension no longer controls pricing. Acceptable — extension now focuses on reservations/availability only.

**NOT adding**: Hourly billing, usage-based, auction-style, or negotiated pricing.

---

### PricingConfig → Setup Wizard
**Decision**: Repurpose PricingConfigResource as Setup Wizard
**Date**: November 2025
**Status**: Final

**Context**: PricingConfig stored slider ranges + pricing in `ptero_pricing_configs` table. After pricing moved to native `dynamic_slider` ConfigOption metadata, this became redundant.

**Rationale**:
- ConfigOption metadata already stores: min, max, step, default, unit, display_unit, display_divisor, pricing model, rates
- No need to maintain two sources of truth
- Wizard creates ConfigOptions directly — simpler data flow
- Dashboard and services now read from ConfigOptions only

**Trade-off**: Existing PricingConfig data lost on migration. Acceptable — admin can use wizard to recreate.

---

### Reservation TTL
**Decision**: 15 minutes, extendable to 30  
**Date**: November 2025  
**Status**: Final

**Context**: How long to hold resources during checkout?

**Rationale**:
- Too short (5 min): Customer can't complete checkout, frustrating
- Too long (1 hour): Resources hoarded, artificial scarcity
- 15 minutes: Enough for checkout, not enough to hurt others
- Extension on checkout page: prevents expiry mid-payment
- Cleanup job runs every minute: resources released promptly

**Trade-off**: Edge case where slow customer loses reservation. Acceptable — they can re-add to cart.

---

## User Interface

### Filament for Admin
**Decision**: Filament v4  
**Date**: November 2025  
**Status**: Final

**Context**: What framework for admin UI?

**Rationale**:
- Paymenter already uses Filament
- Consistent UX for admins (same patterns, same styling)
- No new dependencies to add
- Rich component library (tables, forms, charts)

**Trade-off**: Tied to Filament patterns. Fine since Paymenter is too.

---

### ~~noUiSlider for Frontend~~ Native dynamic_slider
**Decision**: Native `dynamic_slider` config option type (replaced noUiSlider)
**Date**: November 2025 (revised)
**Status**: Superseded

**Original Decision**: noUiSlider via CDN

**Revision (Nov 2025)**: Moved to native Paymenter `dynamic_slider` config option type with Alpine.js.

**Rationale for change**:
- Native integration with Paymenter's config option system
- No external dependencies (Alpine.js already loaded)
- Simpler architecture — no head hook injection needed
- Single source of truth for slider + pricing
- Supports linear, tiered, and base_addon pricing models natively

**Trade-off**: Less styling flexibility than noUiSlider. Acceptable — consistent with Paymenter's native slider.

---

## Algorithms

### Best-Fit Node Selection
**Decision**: Weighted headroom scoring (50% mem, 35% disk, 15% cpu)  
**Date**: November 2025  
**Status**: Final

**Context**: When multiple nodes can fit a request, which to choose?

**Options considered**:
1. First-fit — fast but unbalanced
2. Most-available — leaves small gaps everywhere
3. Least-available (best-fit) — packs efficiently but risks hotspots
4. Weighted headroom — balances by resource importance

**Rationale**:
- Memory: 50% weight — most upgraded, hardest to migrate
- Disk: 35% weight — data migration is slow
- CPU: 15% weight — often oversold, easier to share
- Score = remaining capacity / total capacity × weight
- Highest score wins (best relative headroom)

**Trade-off**: More complex than first-fit. Worth it for better distribution.

---

## Changes Log

| Date | Decision | Change |
|------|----------|--------|
| Nov 2025 | PricingConfig | Converted to Setup Wizard, removed ptero_pricing_configs table |
| Nov 2025 | Pricing Models | Moved tiered/base_addon from extension to Paymenter core native `dynamic_slider` |
| Nov 2025 | Frontend Sliders | Replaced noUiSlider with native `dynamic_slider` config option type |
| Nov 2025 | Initial | All decisions documented |

---

## How to Propose a Change

If you believe a decision should be reconsidered:

1. Check the "NOT reconsidering unless" clause if present
2. Document what changed (new information, failed assumption)
3. Propose alternative with trade-off analysis
4. Update this file with "Revised" status and new rationale

## Decisions locked 2026-04-22

These decisions were made after the full codebase audit (sessions `ses_24c9dae2dffeSpxiEGlGCd5B4C` and `ses_24c9d4dd8ffecFFWJ2Uf4wI0Eb`) and a Paymenter-core pricing capability investigation (session `ses_24c139d79ffeXOiyEsd3b2c0xz`).

### 1. Pricing ownership direction

Paymenter core is the intended pricing authority for `dynamic_slider`. The Phase 1 investigation concluded that core carried four structural defects:

1. **Per-slider base-price duplication.** `app/Livewire/Products/Checkout.php:102-137` and `app/Models/CartItem.php:45-116` sum `plan->price + calculateDynamicPrice()` for every slider. Because each slider's `calculateDynamicPrice()` already includes `pricing.base_price`, enabling memory+cpu+disk sliders with `base_price=5` charges `plan_price + 15` instead of `plan_price + 5`.
2. **`base_plus_addon` falls through to linear.** `app/Models/ConfigOption.php:61-65` match statement has no `base_plus_addon` arm; it silently prices as linear.
3. **Recalculation paths are blind to dynamic sliders.** `app/Models/Service.php:207-235` iterates `configValue` rows only; slider selections stored as service properties are invisible to renewal invoicing (`app/Console/Commands/CronJob.php:57-93`).
4. **Upgrade flow incompatible with numeric slider values.** `app/Livewire/Services/Upgrade.php:60-127` is built around child option IDs, not numeric values.

Fixes landed on our Paymenter fork via **`dp-core-01-pricing-patches`** — fork-only, not upstream.

**dp-09 (Apr 2026):** `dp-core-01` is now shipped. The extension scaffolding called out above has been retired:
- `PricingCalculatorService` replaced by `SliderConfigReaderService` for config reads only.
- `/pricing/calculate` now calls core pricing methods directly.
- `PricingConfigValidator` removed; SetupWizard now uses core `DynamicSliderPricingRule`.

### 2. Canonical addon pricing model name

`base_addon` is the single canonical name. `base_plus_addon` is retired. All docs, the SetupWizard, the validator, and runtime code use `base_addon`. The `base_plus_addon` alias was removed from `PricingConfigValidator` in the dp-07 Phase 4 cleanup.

### 3. `released` reservation state

Deleted. The observable lifecycle is: `pending` → `{confirmed, expired, cancelled}`. The `released` enum member was present in the schema and docs but no service method ever set it. If a post-confirm provisioning-failure state is needed in the future, introduce `provision_failed` (a concrete meaning) rather than re-adding `released`.

### 4. Per-node capacity exposure

Admin-only. Customers never see raw node-level data (node names, FQDNs, maintenance flags, per-node capacity). The customer signal for "this location is near capacity" is the slider clamping to the real allocatable maximum. The `/availability/{locationId}/nodes` route was moved to the admin middleware group in dp-07 Phase 4. The customer-facing availability endpoint returns only aggregate per-location maxima.

### 5. SetupWizard feature-test shipped status

Unit coverage accepted for dp-06. The full Filament-action lifecycle end-to-end test is deferred to **dp-13** (SetupWizard atomicity + audit-log reliability), since that plan touches `ConfigOptionSetupService` and it's the natural place to wire the E2E test. The skipped placeholder in `tests/Feature/SetupWizardValidationTest.php` carries a `// TODO dp-13:` marker tracking this.

### 6. Test Isolation Mandate (dp-13, Apr 2026)

Extension phpunit MUST set `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `BCRYPT_ROUNDS=4`, `PULSE_ENABLED=false`, `TELESCOPE_ENABLED=false` — mirroring the root `phpunit.xml`. Failure to do so risks polluting the shared Redis/file cache on a development host, corrupting the settings key read by running web workers. This caused a production outage on 2026-04-23. The `bootstrap.php` guard provides a second line of defence: it requires `APP_ENV=testing` and accepts only `paymenter_test`, `:memory:`, or a new `dynamic-pterodactyl-test-*/database.sqlite` path under the system temp directory. File-backed SQLite is claimed by atomically creating its private directory and database and holding the exclusive file handle; every pre-existing path is rejected. Empty database names, arbitrary paths, and filename-based trust are forbidden. See `incidents.md` for forensics.

### 7. SetupWizard Atomicity Contract (dp-13, Apr 2026)

`ConfigOptionSetupService::createDynamicSliderOptions()` MUST execute all DB writes (resource sliders + location option + children) inside a single `DB::transaction()`. Either all options land or none do. The audit entry is written AFTER the transaction commits; audit failure is best-effort (logged via `Log::warning` + `report()`) and does not roll back the business data.

### 8. Capacity-Alert Delivery Contract (dp-12, Apr 2026)

`AlertService::checkCapacityAlerts()` is the single entry point for capacity threshold scanning. It is scheduled by `DynamicPterodactyl.php::boot()` every 5 minutes with `withoutOverlapping()`. Delivery channels: `mail` (if `email_notifications` is true) and `webhook` (if `webhook_url` is set and `webhook_notifications` is enabled). Email fan-out uses `User::whereNotNull('role_id')->get()` — same recipient rule as `notifyShortfall()` (dp-04). Failures on one recipient do not abort the loop; they emit `Log::warning` + `report()` semantics.

### 9. Capacity-Alert Scheduler Cadence (dp-12, Apr 2026)

Cadence: `everyFiveMinutes()`. Rationale: `ptero_alert_configs.cooldown_minutes` defaults to 60; a 5-minute scan catches threshold crossings within one cooldown window without API hammering. Cadence is code-only (no runtime toggle) — change requires a code edit + deploy.

### 10. Reservation Funnel Observability Schema (dp-12, Apr 2026)

Reservation state transitions write rows to `ptero_audit_logs` via the shared `AuditsExtensionActions` trait (dp-13). Per-transition rows for `confirm` / `cancel` use `action` values `reservation_confirmed` and `reservation_cancelled` with `entity_type = resource_reservation` and `entity_id = reservation id`. Batch rows for `cleanupExpired` use `action = reservations_expired_batch` with `entity_id = 0` and `count` in `new_values`. Token values are logged as `token_prefix` (first 8 chars) only — full tokens must never land in audit JSON.

## Process: Out-of-scope finding handling (added dp-10, Apr 2026)

When implementing any dp-NN plan, if the agent or CodeRabbit identifies a change that does not fit the current PR's scope:

1. Identify the correct destination plan (dp-11 = auth, dp-12 = observability, dp-13 = E2E, new = create stub).
2. Append the finding to that plan's "Deferred from <source>" section with: description, file:line, citation, date.
3. Post reasoning on the thread — either a plain GitHub comment or an `@coderabbitai` reply (both work on Pro). Include the three-part rationale (what CR claimed → why wrong/out-of-scope → intended design). Then resolve the thread via UI / `gh api`. `@coderabbitai` mentions are a first-class tool, not forbidden.
4. Do NOT silently expand the current PR scope.


### 11. CodeRabbit Review Mandate and PR-Author Identity (dp-process-audit, Apr 2026)

Every PR against `dynamic-slider` (and any parent-repo branch) MUST follow `.sisyphus/templates/ralph-loop-contract.md` with the mechanical gate `.sisyphus/templates/ralph-loop-verify.sh <PR_NUMBER>` run immediately before `gh pr merge`. Triggered by the 2026-04-24 incident where PRs #10 (dp-13) and #11 (dp-12) merged within 1–3 minutes of opening, zero CodeRabbit reviews submitted.

Root cause: CodeRabbit evaluates entitlements against the GitHub login on the PR itself (whoever ran `gh pr create`), NOT the commit author. PRs #10 and #11 were opened while `gh` was active as `ImStillBlue` (the host's default account), which resolves to a Free-tier identity, so CodeRabbit skipped review. PR #9 opened as `Jordanmuss99` (Pro) with the exact same commit authors received 3 reviews — proving commit author is not the determinant. PR author is immutable on GitHub; once opened under the wrong account, the PR must be closed and reopened.

PR author identity rule (PRIMARY): before `gh pr create`, the orchestrator and any PR-creating subagent MUST run `gh auth switch -u Jordanmuss99` and verify with `gh api /user --jq .login` that the active login is `Jordanmuss99`. Abort the PR-create step otherwise.

Commit author email (secondary): git config MUST be `user.name = Jordanmuss99` and `user.email = 164892154+Jordanmuss99@users.noreply.github.com` (noreply form — default, avoids the GH007 push rejection that blocks `jordanmuss@hotmail.com`). Use the hotmail address only if the Jordanmuss99 account email-privacy setting is changed to allow public email pushes. Historical dp-11/dp-13/dp-12 commits using the noreply form are grandfathered — those PRs are merged and PR #9 proved the noreply form doesn't break auto-review.

Orchestrator verification: when a subagent claims a PR is opened or merged, the orchestrator MUST independently run `gh pr view <N> --json author,createdAt,mergedAt,reviews` and treat `author.login != "Jordanmuss99"` OR `mergedAt - createdAt < 3 minutes` OR `cr_review_count < 1` as a contract violation regardless of the subagent's text. See `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md` for the remediation protocol.
