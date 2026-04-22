# CHANGELOG.md

Milestone and release notes. For day-to-day progress, see PROGRESS.md.

---

## [Unreleased]

### Fixed
- Payment-time reservation confirmation now excludes the reservation itself from pending-capacity math, so exact-fit purchases can confirm successfully.
- Reservation create requests now enforce product slider bounds and reject unconfigured products instead of persisting arbitrary resource selections.
- Availability `has_capacity` now requires memory, CPU, and disk to all be positive, with per-resource booleans exposed in the API response.

### Added
- Reservation create endpoint now supports `Idempotency-Key` / `idempotency_key` dedupe with active-reservation reuse semantics.

### Changed
- `released` reservation status removed from schema and PHP enum. Lifecycle: `pending → confirmed | expired | cancelled`.
- `base_plus_addon` pricing model alias removed from `PricingConfigValidator`. Use `base_addon`.
- `/availability/{locationId}/nodes` API route moved to admin-only middleware group.

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
