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
