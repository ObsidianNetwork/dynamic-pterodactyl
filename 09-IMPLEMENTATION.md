# Implementation Guide

> **Related docs**: All other documents in this folder

---

## File Structure

```
extension/Others/DynamicPterodactyl/
├── DynamicPterodactyl.php              # Main extension class
├── composer.json                        # Dependencies (if any)
│
├── database/
│   └── migrations/
│       ├── 2025_01_01_000001_create_ptero_resource_reservations_table.php
│       ├── 2025_01_01_000002_create_ptero_pricing_configs_table.php
│       ├── 2025_01_01_000003_create_ptero_audit_logs_table.php
│       └── 2025_01_01_000004_create_ptero_alert_configs_table.php
│
├── Filament/
│   ├── Pages/
│   │   ├── Dashboard.php
│   │   ├── Analytics.php
│   │   ├── NodeMonitoring.php
│   │   ├── AuditLogPage.php
│   │   └── Settings.php
│   └── Resources/
│       ├── PricingConfigResource.php
│       ├── PricingConfigResource/
│       │   └── Pages/
│       │       ├── CreatePricingConfig.php
│       │       ├── EditPricingConfig.php
│       │       └── ListPricingConfigs.php
│       ├── ReservationResource.php
│       └── AlertConfigResource.php
│
├── Http/
│   └── Controllers/
│       ├── Api/
│       │   ├── AvailabilityController.php
│       │   ├── PricingController.php
│       │   └── ReservationController.php
│       └── AdminController.php
│
├── Jobs/
│   ├── CleanupExpiredReservations.php
│   └── CheckCapacityAlerts.php
│
├── Models/
│   ├── PricingConfig.php
│   ├── ResourceReservation.php
│   ├── AuditLog.php
│   └── AlertConfig.php
│
├── resources/
│   └── views/
│       ├── head-scripts.blade.php       # Frontend JS/CSS injection
│       └── admin/
│           ├── dashboard.blade.php
│           ├── analytics.blade.php
│           ├── node-monitoring.blade.php
│           ├── audit-log.blade.php
│           ├── audit-detail.blade.php
│           └── settings.blade.php
│
├── routes/
│   ├── api.php
│   └── web.php
│
└── Services/
    ├── ResourceCalculationService.php
    ├── NodeSelectionService.php
    ├── ReservationService.php
    ├── PricingCalculatorService.php
    ├── AuditLogService.php
    └── AlertService.php
```

---

## Implementation Roadmap

### Phase 1: Foundation (Weeks 1-2)

**Goal**: Database and core services working

| Task | Est. Hours | Deliverable |
|------|------------|-------------|
| Database migrations | 4h | 4 migration files |
| Eloquent models | 2h | 4 model classes |
| ResourceCalculationService | 6h | API integration, availability calc |
| NodeSelectionService | 4h | Best-fit algorithm |
| Unit tests for services | 4h | Test coverage |

**Milestone**: Can query Pterodactyl and calculate node availability.

### Phase 2: Reservation System (Weeks 3-4)

**Goal**: Reservations with concurrency handling

| Task | Est. Hours | Deliverable |
|------|------------|-------------|
| ReservationService | 8h | Create, confirm, cancel, extend |
| Pessimistic locking | 4h | Transaction handling |
| Cleanup job | 2h | Scheduled task |
| API endpoints | 4h | Controllers + routes |
| Integration tests | 4h | End-to-end reservation flow |

**Milestone**: Can create reservations that correctly track availability.

### Phase 3: Pricing Engine (Weeks 5-6)

**Goal**: All three pricing models working

| Task | Est. Hours | Deliverable |
|------|------------|-------------|
| PricingCalculatorService | 8h | Linear, tiered, base+addon |
| Pricing API endpoint | 2h | Calculate endpoint |
| Price breakdown response | 2h | Itemized output |
| Unit tests for pricing | 4h | Edge case coverage |

**Milestone**: Can calculate prices for any configuration.

### Phase 4: Admin UI - Core (Weeks 7-8)

**Goal**: Basic admin functionality

| Task | Est. Hours | Deliverable |
|------|------------|-------------|
| Dashboard page | 6h | Stats, connection status, capacity |
| PricingConfigResource | 10h | 5-tab visual editor |
| Settings page | 4h | Connection, defaults, display |
| Form-to-JSON conversion | 4h | mutateFormDataBeforeSave |

**Milestone**: Admins can configure pricing without JSON editing.

### Phase 5: Admin UI - Advanced (Weeks 9-10)

**Goal**: Full admin capabilities

| Task | Est. Hours | Deliverable |
|------|------------|-------------|
| ReservationResource | 6h | List, extend, cancel |
| NodeMonitoring page | 4h | Real-time capacity display |
| Analytics page | 6h | Revenue, conversion, trends |
| Import/Export | 4h | Pricing config backup |

**Milestone**: Complete admin dashboard.

### Phase 6: Frontend (Weeks 11-12)

**Goal**: Customer-facing sliders

| Task | Est. Hours | Deliverable |
|------|------------|-------------|
| JavaScript slider component | 8h | noUiSlider integration |
| Real-time price updates | 4h | Debounced API calls |
| Availability limits | 4h | Dynamic max values |
| CSS styling | 4h | Responsive, dark mode |
| Cross-browser testing | 4h | Chrome, Firefox, Safari, Edge |

**Milestone**: Customers can select resources and see live pricing.

### Phase 7: Alerts & Audit (Week 13)

**Goal**: Operational monitoring

| Task | Est. Hours | Deliverable |
|------|------------|-------------|
| AuditLogService | 4h | Logging all changes |
| Audit log page | 4h | View/filter/export |
| AlertService | 6h | Threshold checking, notifications |
| AlertConfigResource | 4h | Admin configuration |
| Webhook integration | 2h | Discord/Slack alerts |

**Milestone**: Full audit trail and capacity alerts.

### Phase 8: Event Integration (Week 14)

**Goal**: Seamless Paymenter integration

| Task | Est. Hours | Deliverable |
|------|------------|-------------|
| CartItem event handlers | 4h | Create/delete reservation |
| Invoice payment handler | 4h | Confirm reservation |
| Service creation handler | 2h | Link service to reservation |
| End-to-end testing | 6h | Full purchase flow |
| Documentation | 4h | README, inline comments |

**Milestone**: Complete, production-ready extension.

---

## Total Timeline

| Phase | Duration | Cumulative |
|-------|----------|------------|
| 1. Foundation | 2 weeks | Week 2 |
| 2. Reservations | 2 weeks | Week 4 |
| 3. Pricing | 2 weeks | Week 6 |
| 4. Admin Core | 2 weeks | Week 8 |
| 5. Admin Advanced | 2 weeks | Week 10 |
| 6. Frontend | 2 weeks | Week 12 |
| 7. Alerts & Audit | 1 week | Week 13 |
| 8. Event Integration | 1 week | Week 14 |

**Total: 14 weeks** (assuming ~20 hours/week)

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
    
    public function test_base_plus_addon_with_no_overage()
    {
        // ...
    }
}
```

### Integration Tests

Test component interactions with real database.

```php
class ReservationFlowTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_concurrent_reservations_dont_oversell()
    {
        // Seed a node with 16GB available
        // Attempt two 10GB reservations concurrently
        // Verify only one succeeds
    }
    
    public function test_expired_reservations_release_capacity()
    {
        // Create reservation
        // Fast-forward time past TTL
        // Run cleanup job
        // Verify capacity is available again
    }
}
```

### Feature Tests

Test full HTTP request/response cycles.

```php
class AvailabilityApiTest extends TestCase
{
    public function test_availability_endpoint_returns_correct_structure()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dynamic-pterodactyl/availability/1');
        
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'location_id',
                    'max_memory',
                    'max_cpu',
                    'max_disk',
                    'node_count',
                    'has_capacity',
                ],
            ]);
    }
}
```

### Browser Tests (Dusk)

Test frontend slider behavior.

```php
class SliderInteractionTest extends DuskTestCase
{
    public function test_slider_updates_price_display()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/product/minecraft-server')
                ->waitFor('.dynamic-ptero-slider')
                ->drag('.noUi-handle', 100, 0)  // Drag memory slider
                ->waitFor('.dynamic-ptero-price-display.updated')
                ->assertSeeIn('.dynamic-ptero-price-total', '$');
        });
    }
}
```

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
4. Register scheduled jobs (cleanup, alerts)
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
