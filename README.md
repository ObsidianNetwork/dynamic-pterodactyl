# Dynamic Resource Sliders for Pterodactyl Integration

**Version:** 3.1.0  
**Status:** Final Design  
**Pattern:** Companion Extension  

---

## Quick Summary

Enable Paymenter customers to select exact RAM, CPU, and Disk amounts using sliders during checkout for Pterodactyl game servers, with real-time availability tracking and automatic node selection.

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Product Configuration                         │
├─────────────────────────────────────────────────────────────────────┤
│  Server Extension: Pterodactyl (built-in, unchanged)                │
│  └── Handles: Server creation, ports, users                         │
├─────────────────────────────────────────────────────────────────────┤
│  Configurable Options:                                              │
│  ├── memory (Number) ─────┐                                         │
│  ├── cpu (Number) ────────┼── Flow to createServer()                │
│  ├── disk (Number) ───────┘                                         │
│  └── location_id (Select) ── Triggers availability fetch            │
├─────────────────────────────────────────────────────────────────────┤
│  DynamicPterodactyl (Companion Extension):                          │
│  ├── Real-time availability API                                     │
│  ├── Resource reservation system (15-min TTL)                       │
│  ├── Interim pricing scaffolding pending dp-core-01                 │
│  ├── No custom frontend assets; native core slider UI               │
│  ├── Admin dashboard & configuration UI                             │
│  └── Event hooks for cart/invoice/service lifecycle                 │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Why Companion Pattern?

| Factor | Standalone | Companion ✓ |
|--------|------------|-------------|
| Lines of code | ~800+ | ~400 |
| Pterodactyl provisioning | Must reimplement | Reuses existing |
| Upstream bug fixes | Manual port | Automatic |
| If extension fails | Product broken | Graceful degradation |

---

## Document Index

| Document | Purpose | Read When... |
|----------|---------|--------------|
| [01-DATABASE.md](01-DATABASE.md) | Schema, migrations, relationships | Setting up tables, understanding data model |
| [02-SERVICES.md](02-SERVICES.md) | Core business logic services | Implementing backend functionality |
| [03-API.md](03-API.md) | REST endpoints, controllers | Building API layer |
| [04-EVENTS.md](04-EVENTS.md) | Cart/invoice event handlers | Integrating with Paymenter lifecycle |
| [05-ADMIN-UI.md](05-ADMIN-UI.md) | Filament dashboard & resources | Building admin interface |
| [06-FRONTEND.md](06-FRONTEND.md) | Native slider architecture, Alpine.js | Implementing customer-facing UI |
| [07-PRICING-MODELS.md](07-PRICING-MODELS.md) | Pricing logic & JSON configs | Understanding/implementing pricing |
| [08-ALGORITHMS.md](08-ALGORITHMS.md) | Node selection, concurrency | Implementing allocation logic |
| [09-IMPLEMENTATION.md](09-IMPLEMENTATION.md) | Roadmap, testing, risks | Planning & project management |

---

## Key Technical Decisions

### Real-Time API (No Caching)
- Query Pterodactyl API directly at checkout time
- Eliminates cache staleness issues
- Proven by PteroSync WHMCS module in production

### Reservation System
- 15-minute TTL holds resources during checkout
- Pessimistic database locking prevents overselling
- Final verification at payment time

### Three Pricing Models
1. **Linear** - Simple per-unit (e.g., $0.50/GB RAM)
2. **Tiered** - Volume discounts at breakpoints
3. **Base + Addon** - Included resources + overage charges

Paymenter core is the intended pricing authority for `dynamic_slider` options. Until the fork-only core patches in `dp-core-01` land, this extension keeps `PricingCalculatorService` as interim pricing scaffolding to compensate for known core defects.

### Frontend Slider
- Customer-facing sliders use Paymenter core's native `dynamic_slider` component
- Rendering is a native HTML range input managed by Alpine.js in the core theme
- Price updates happen client-side, with Livewire entanglement keeping cart state in sync

---

## File Structure

```
extensions/Others/DynamicPterodactyl/
├── DynamicPterodactyl.php          # Main extension class
├── database/migrations/            # 4 migration files
├── Admin/
│   ├── Pages/                      # Dashboard, Analytics, Settings, etc.
│   └── Resources/                  # PricingConfig, Reservation, Alert
├── Http/Controllers/               # API and Admin controllers
├── Models/                         # Eloquent models
├── resources/views/                # Blade templates
├── routes/                         # API and web routes
└── Services/                       # Core business logic
```

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `ptero_resource_reservations` | Temporary resource holds during checkout |
| `ptero_audit_logs` | Admin action tracking |
| `ptero_alert_configs` | Capacity alert thresholds |

---

## Cross-Reference: Common Tasks

| Task | Primary Doc | Also See |
|------|-------------|----------|
| Add new pricing model | 07-PRICING-MODELS | 02-SERVICES |
| Fix reservation bug | 02-SERVICES | 08-ALGORITHMS |
| Modify admin dashboard | 05-ADMIN-UI | - |
| Change slider behavior | 06-FRONTEND | 03-API |
| Add new event hook | 04-EVENTS | 02-SERVICES |
| Understand node selection | 08-ALGORITHMS | 02-SERVICES |

---

## Quick Start for Implementation

1. **Database first**: Follow [01-DATABASE.md](01-DATABASE.md) to create migrations
2. **Services**: Implement services from [02-SERVICES.md](02-SERVICES.md)
3. **API**: Build endpoints per [03-API.md](03-API.md)
4. **Events**: Wire up handlers from [04-EVENTS.md](04-EVENTS.md)
5. **Admin**: Create Filament UI from [05-ADMIN-UI.md](05-ADMIN-UI.md)
6. **Frontend**: Add sliders using [06-FRONTEND.md](06-FRONTEND.md)

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | Nov 2025 | Initial cached architecture |
| 2.0 | Nov 2025 | Real-time API approach |
| 3.0 | Nov 2025 | Companion Extension pattern |
| 3.1 | Nov 2025 | Comprehensive Admin UI, split documentation |
