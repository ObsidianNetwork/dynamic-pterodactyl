# Implementation Guide

> **Related docs**: All other documents in this folder

---

## File Structure

```
extension/Others/DynamicPterodactyl/
├── DynamicPterodactyl.php              # Main extension class
│
├── database/
│   └── migrations/
│       ├── 2025_01_01_000001_create_ptero_resource_reservations_table.php
│       ├── 2025_01_01_000002_create_ptero_pricing_configs_table.php
│       ├── 2025_01_01_000003_create_ptero_audit_logs_table.php
│       └── 2025_01_01_000004_create_ptero_alert_configs_table.php
│
├── Admin/
│   ├── Pages/
│   │   ├── Dashboard.php
│   │   ├── NodeMonitoring.php
│   │   ├── AuditLogPage.php
│   │   └── SetupWizard.php
│   └── Resources/
│       ├── ReservationResource.php
│       └── AlertConfigResource.php
│
├── Http/
│   └── Controllers/
│       ├── Api/
│       │   ├── AvailabilityController.php
│       │   ├── PricingController.php
│       │   └── ReservationController.php
│       └── Admin/
│           ├── AdminReservationController.php
│           └── AdminCapacityController.php
│
├── Models/
│   ├── ResourceReservation.php
│   ├── AuditLog.php
│   └── AlertConfig.php
│
├── resources/
│   └── views/
│       └── admin/
│           ├── dashboard.blade.php
│           ├── node-monitoring.blade.php
│           ├── audit-log.blade.php
│           └── setup-wizard.blade.php
│
├── routes/
│   └── api.php
│
└── Services/
    ├── ResourceCalculationService.php
    ├── NodeSelectionService.php
    ├── ReservationService.php
    ├── PricingCalculatorService.php
    ├── AuditLogService.php
    ├── AlertService.php
    └── ConfigOptionSetupService.php
```

---

## Implementation Roadmap

| Phase | Status | Reference |
|---|---|---|
| Database schema | ✅ Shipped | dp-08 idempotency + drop_released migrations |
| Service layer | ✅ Shipped | dp-09 cleanup; 8 services live |
| API endpoints | ✅ Shipped | dp-05 admin API; dp-08 reservation hardening |
| Filament admin UI | ✅ Shipped | dp-13 SetupWizard atomicity |
| Pricing model | ✅ Delegated to core | core `DynamicSliderPricingRule` (dp-core-01) |
| Frontend slider | ✅ Shipped | dp-10 a11y; dp-core-02 Blade partial |
| Capacity alerts | ✅ Shipped | dp-12 scheduled task + email; dp-17 delivery log |
| Authorization hardening | ✅ Shipped | dp-11 policy + cart-item ownership |

## Current Backlog

Improvement items from the 2026-04-26 audit pass:

- **dp-14**: Rate-limit reservation endpoints to 10 req/min per authenticated user — ✅ Shipped (PR #17)
- **dp-16**: Documentation sync: align 03-API + 05-ADMIN-UI + 09-IMPLEMENTATION with post-dp-13 architecture — 🔄 Current
- **dp-17**: Alert delivery log + `AlertDeliveryFailed` escalation event — ✅ Shipped (PR #18)
- **dp-18**: Capacity-fanout performance: batch Pterodactyl reads in `ResourceCalculationService` to reduce O(locations × nodes) API calls in admin views — Pending

---

## Testing Strategy

### Unit Tests

Test individual service methods in isolation.

```php
class PricingCalculatorServiceTest extends TestCase
{
    public function test_linear_pricing_calculation()
    {
        $service = new PricingCalculatorService();
        
        // Mock pricing config
        $this->mockPricingConfig(1, [
            'pricing_model' => 'linear',
            'pricing_config' => json_encode([
                'base_price' => 5,
                'memory_per_gb' => 0.50,
                'cpu_per_core' => 2.00,
                'disk_per_gb' => 0.02,
            ]),
        ]);
        
        $result = $service->calculate(1, [
            'memory' => 8192,  // 8GB
            'cpu' => 400,      // 4 cores
            'disk' => 102400,  // 100GB
        ]);
        
        $this->assertEquals(19.00, $result['total']);
        $this->assertEquals('linear', $result['model']);
    }
    
    public function test_tiered_pricing_with_multiple_tiers()
    {
        // ...
    }
    
    public function test_base_addon_with_no_overage()
    {
        // ...
    }
}
```

### Integration Tests

Integration tests against a real or mocked Pterodactyl API are not yet implemented. The suite is unit-level with limited feature coverage.

Current test coverage under `tests/Unit/` is mostly mocked service/listener validation. `tests/Feature/` currently covers admin API authorization/response behavior plus the skipped SetupWizard placeholder.

### Feature Tests

Test full HTTP request/response cycles.

```php
class AdminApiTest extends TestCase
{
    public function test_admin_capacity_endpoint_returns_structure()
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/dynamic-pterodactyl/admin/capacity');
        
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'locations',
                    'generated_at',
                ],
            ]);
    }
}
```

### Browser Tests

No browser/E2E suite is implemented in this extension yet. Frontend slider verification is currently manual.

## SetupWizard Atomicity + Audit Reliability (dp-13)

`createDynamicSliderOptions()` is wrapped in `DB::transaction()`. Mid-batch failures (pricing rule throw, FK violation, transient DB error) roll back all writes atomically. The audit entry is written post-commit; failure is structured-logged and reported but does not affect the transaction outcome.

`safeAudit()` lives in `Services/Concerns/AuditsExtensionActions.php` and is used by both `ReservationService` and `ConfigOptionSetupService`. Audit failures emit `Log::warning('extension audit write failed', [...])` so they appear in normal application logs even without a configured exception reporter.

---

## Risk Mitigation

### Risk 1: Pterodactyl API Changes

**Probability**: Low  
**Impact**: High (breaks availability calculation)

**Mitigation**:
- Abstract API calls behind interface
- Version check on connection test
- Monitor Pterodactyl changelog

### Risk 2: Database Deadlocks

**Probability**: Medium (under high concurrency)  
**Impact**: Medium (failed reservations)

**Mitigation**:
- Transaction retry logic (5 attempts)
- Proper index ordering
- Monitor deadlock frequency in logs

### Risk 3: Stale Availability Data

**Probability**: Low (real-time API)  
**Impact**: Medium (overselling)

**Mitigation**:
- Final verification at payment
- No caching of availability
- Reservation system as buffer

### Risk 4: Frontend JavaScript Conflicts

**Probability**: Medium  
**Impact**: Low (broken sliders, not data loss)

**Mitigation**:
- Namespace all code (`window.DynamicPterodactyl`)
- No global jQuery modifications
- Graceful degradation (fallback to number inputs)

### Risk 5: Admin Misconfiguration

**Probability**: High  
**Impact**: Medium (incorrect pricing)

**Mitigation**:
- Form validation prevents invalid configs
- Preview calculator before saving
- Audit logging of all changes

---

## Deployment Checklist

### Pre-Deployment

- [ ] All migrations tested on copy of production data
- [ ] Pterodactyl API credentials configured
- [ ] Connection test passes
- [ ] At least one pricing config created
- [ ] Backup database

### Deployment Steps

1. Enable maintenance mode
2. Run migrations: `php artisan migrate`
3. Clear caches: `php artisan cache:clear`
4. Register scheduled jobs (cleanup is wired; alerts are not yet scheduled)
5. Disable maintenance mode
6. Verify dashboard loads
7. Test one product with sliders
8. Monitor error logs for 30 minutes

### Post-Deployment

- [ ] Verify scheduled jobs running (check `telescope` or logs)
- [ ] Create test reservation, let it expire
- [ ] Complete full purchase flow
- [ ] Check audit log records creation

---

## Rollback Plan

If critical issues discovered:

1. **Immediate**: Deactivate all pricing configs (disables sliders)
2. **If needed**: Roll back migration (data loss of configs)
   ```bash
   php artisan migrate:rollback --step=4
   ```
3. **Emergency**: Remove extension folder entirely

Data in `ptero_resource_reservations` is ephemeral and can be safely dropped.

## Scheduler wiring status

Expired-reservation cleanup and `AlertService::checkCapacityAlerts()` are both scheduled in `DynamicPterodactyl.php::boot()`. Cleanup runs every minute; capacity checks run every five minutes with `withoutOverlapping()`.

## Capacity Alerts + Reservation Observability (dp-12)

`AlertService::checkCapacityAlerts()` is scheduled every 5 minutes in `DynamicPterodactyl.php::boot()`. For each active `ptero_alert_configs` row it: (1) respects `cooldown_minutes` via `last_notification_at`, (2) reads live utilization via `ResourceCalculationService`, (3) dispatches to email (all admins with `role_id`) and/or webhook, (4) writes one `capacity_alert_sent` audit row summarising channels + severity + breached resources.

`ReservationService::{confirm,cancel,cleanupExpired}` write audit rows on successful state transitions. `confirm` / `cancel` are per-reservation. `cleanupExpired` writes one batch row per run with `count`. Token values are stored as `token_prefix` only.

Both services use the shared `AuditsExtensionActions` trait (dp-13) — audit failure is best-effort and does not abort business logic.

---

## Performance Benchmarks

Target performance (measure during testing):

| Operation | Target | Acceptable |
|-----------|--------|------------|
| Availability fetch | < 200ms | < 500ms |
| Price calculation | < 50ms | < 100ms |
| Reservation creation | < 300ms | < 500ms |
| Dashboard load | < 1s | < 2s |
| Slider response | < 100ms | < 200ms |

If targets not met, profile with Laravel Telescope or Debugbar.
