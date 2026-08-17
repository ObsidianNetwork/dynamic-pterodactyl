# CHANGELOG.md

Milestone and release notes. For day-to-day progress, see PROGRESS.md.

---

## [Unreleased]

### Added
- Guest-safe complete-vector checkout quotes and authenticated fixed-node
  upgrade quotes with step-aligned live bounds.
- Explicit per-node CPU capacity and basis-point overcommit policies.
- Exact primary/additional allocation claims, fixed-port mappings, and
  dedicated-IP selection.
- Seven-day invoice guarantees, non-expiring paid commitments,
  provisioning/upgrade leases, queue retries, reconciliation, and operator
  attention states.
- Capacity-aware RAM/CPU/disk upgrades with immutable source/target snapshots
  and positive-delta reservations.
- Durable Pterodactyl server, user, panel, node, nest, egg, resource, and
  allocation identity for lifecycle actions.
- Managed-node isolation for Paymenter static provisioning and raw upgrades.
- Explicit extension migration/readiness command and forward-only upload gate.
- PHP 8.3/8.4 cross-repository CI on SQLite, MariaDB 11, and MariaDB 12.

### Changed
- Rebased the companion implementation onto Paymenter 1.5.7, Filament 5,
  and Livewire 4.
- Replaced customer reservation tokens and independent availability maxima
  with one server-owned cart hold and complete-vector quote contract.
- Provisioning now overrides the exact node, location, RAM, CPU, disk, and
  allocation IDs, then activates the service only after reconciliation.
- Dynamic products force quantity one; wizard reruns retire disabled sliders
  and deselected locations safely.
- Pricing base is stored once on the plan and all range, step, decimal, and
  final-tier coverage rules are validated server-side.
- Dynamic service status, identity, configuration, product stock, and paid
  upgrade mutations are owned by explicit fulfillment coordinators.

### Fixed
- Unsupported Pterodactyl `filter[location_id]` requests; all node pages are
  read and location-filtered locally.
- RAM/disk maxima coming from different nodes, missing CPU inventory,
  maintenance/private nodes, unsafe unlimited limits, and optimistic API
  permission fallbacks.
- Duplicate holds, stale slider idempotency, guest ownership drift,
  URL/session token exposure, and fail-open cart/checkout paths.
- Paid-active-without-server state, one-attempt provisioning, stale worker
  completion, cancellation/create races, and external-ID-only lifecycle
  targeting.
- Unencrypted extension API credentials and silent extension migration
  success.
- Wizard base-price duplication, stale options/locations, off-step values,
  uncovered pricing tiers, unsupported quantity, and raw dynamic upgrades.
- Cross-panel node-ID collisions and mutable legacy lifecycle identity.

---

## [3.1.0] — 2026-04-21

### Fixed
- Reservation lifecycle race: cart-clear no longer cancels reservations consumed by checkout
- InvoicePaidListener now checks confirm() return value and logs warning on state drift

### Added
- Scheduled cleanup cron for expired pending reservations
- expires_at predicate in ReservationService::confirm() — expired reservations cannot be confirmed

### Changed
- Extension metadata attribute added
- Route throttling (30 req/min) on availability and pricing endpoints
- Documentation updated to match current implementation
- skeleton/ directory removed

---

## Documentation Versions

### [3.1] - 2025-11-28
**Added**
- Comprehensive admin UI specification (05-ADMIN-UI.md)
- Filament dashboard with connection status and capacity overview
- PricingConfigResource with 5-tab visual editor
- ReservationResource with extend/cancel actions
- AlertConfigResource for capacity notifications
- AuditLogPage for change tracking
- Settings page with test connection
- Two new database tables: ptero_audit_logs, ptero_alert_configs
- Two new services: AuditLogService, AlertService

### [3.0] - 2025-11-27
**Changed**
- Architecture: Switched from Standalone to Companion Extension pattern
- Rationale: Reuse 300 lines of provisioning code from built-in extension

**Added**
- Graceful degradation design (extension failure doesn't break product)

### [2.0] - 2025-11-27
**Changed**
- Data fetching: Switched from caching to real-time Pterodactyl API
- Rationale: PteroSync analysis showed caching unnecessary and risky

**Removed**
- Cache tables and invalidation logic

### [1.0] - 2025-11-27
**Added**
- Initial design document
- Cached architecture (later revised)
- Basic slider concept

---

## Implementation Releases

*Will be added as implementation progresses*

### [0.1.0] - TBD (Phase 1 Complete)
- Database migrations
- Core models
- ResourceCalculationService
- NodeSelectionService
- Pterodactyl API integration

### [0.2.0] - TBD (Phase 2 Complete)
- ReservationService with locking
- Cleanup scheduled job
- Reservation API endpoints

### [0.3.0] - TBD (Phase 3 Complete)  
- PricingCalculatorService
- All three pricing models
- Pricing API endpoints

### [0.4.0] - TBD (Phase 4 Complete)
- Admin dashboard
- PricingConfigResource
- Settings page

### [0.5.0] - TBD (Phase 5 Complete)
- ReservationResource
- NodeMonitoring page
- Analytics page

### [0.6.0] - TBD (Phase 6 Complete)
- Frontend JavaScript sliders
- Real-time price updates
- Availability-limited maximums

### [0.7.0] - TBD (Phase 7 Complete)
- AuditLogService and page
- AlertService and configuration
- Webhook notifications

### [1.0.0] - TBD (Phase 8 Complete)
- Paymenter event integration
- Full purchase flow working
- Production ready

---

## Versioning

- **Documentation**: Major.Minor (3.1, 3.0, etc.)
- **Implementation**: Semantic versioning (0.1.0 → 1.0.0)
- **1.0.0**: First production-ready release
