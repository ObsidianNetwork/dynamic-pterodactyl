# CLAUDE.md

Quick reference for implementing this extension. Read this first.

## Project Context

Paymenter extension enabling dynamic resource sliders (RAM, CPU, Disk) for Pterodactyl game server products with real-time pricing and availability.

**Pattern**: Companion Extension — enhances the built-in Pterodactyl extension without reimplementing server provisioning.

## Key Decisions (Already Settled)

These were debated and decided. Don't re-litigate without checking DECISIONS.md:

- ✅ Real-time Pterodactyl API — NO caching
- ✅ 15-minute reservation TTL with pessimistic locking
- ✅ Three pricing models only: linear, tiered, base+addon (now in Paymenter core)
- ✅ Filament for admin UI (matches Paymenter)
- ✅ Native `dynamic_slider` config option type for frontend (replaced noUiSlider)
- ✅ Best-fit node selection with weighted scoring (50% mem, 35% disk, 15% cpu)
- ✅ Extension focuses on reservations/availability only (pricing moved to core)

## Conventions

### Database
- Table prefix: `ptero_*`
- Money: `decimal(10,2)`
- Memory/Disk: stored in **MB** (display as GB in UI)
- CPU: stored as **percentage** (100 = 1 core, 400 = 4 cores)
- JSON columns use `snake_case` keys

### Code
- Services: one class per concern, in `Services/`
- Models: in `Models/`, match table name without prefix
- Controllers: thin, delegate to services
- Filament: Pages for dashboards, Resources for CRUD

### Naming
```
PricingConfig          (model)
ptero_pricing_configs  (table)
PricingConfigResource  (Filament resource)
PricingCalculatorService (service)
```

## Documentation Map

| Need to... | Read |
|------------|------|
| Understand tables/models | 01-DATABASE.md |
| Implement business logic | 02-SERVICES.md |
| Build API endpoints | 03-API.md |
| Hook into Paymenter events | 04-EVENTS.md |
| Build admin interface | 05-ADMIN-UI.md |
| Build customer sliders | 06-FRONTEND.md |
| Understand pricing math | 07-PRICING-MODELS.md |
| Understand node selection | 08-ALGORITHMS.md |
| See roadmap/phases | 09-IMPLEMENTATION.md |

## Extension Location

```
extensions/Others/DynamicPterodactyl/
```

## Useful Commands

```bash
# Database
php artisan migrate
php artisan migrate:rollback --step=4

# Cache (clear after config changes)
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Scheduler (runs cleanup + alerts)
php artisan schedule:work

# Manual testing
php artisan tinker
>>> app(ResourceCalculationService::class)->testConnection()
```

## Paymenter Patterns to Follow

Before implementing, check how the built-in Pterodactyl extension does things:
```
app/Extensions/Servers/Pterodactyl/
```

Key files to reference:
- `Pterodactyl.php` — main extension class structure
- How it registers routes
- How it uses `ExtensionHelper::getConfig()`

## When Implementing

1. Check PROGRESS.md for current state
2. Read relevant doc section
3. Follow existing Paymenter patterns
4. Update PROGRESS.md when done
5. Note any decisions/blockers encountered

## Common Gotchas

- Paymenter uses Filament v4, not v3
- CSRF token required for API POST requests from frontend
- Cart item `properties` is JSON — merge, don't replace
- Pterodactyl API rate limit: 240/min (we use ~10)

---

## Context Compaction Protocol

**When nearing context limits, BEFORE compacting:**

1. **Update PROGRESS.md immediately** with:
   - Current task and exact stopping point
   - Any uncommitted decisions
   - What's working, what's broken
   - Next specific action to take

2. **If debugging**, add to PROGRESS.md:
   - The error/problem
   - What you've tried
   - Current hypothesis

3. **If mid-implementation**, note in PROGRESS.md:
   - Files created/modified this session
   - What's complete vs. partial
   - Any temporary code or TODOs added

4. **Flush any new decisions** to DECISIONS.md

5. **Tell the user** you're about to compact so they can save context if needed

### Quick Checkpoint Template

Copy this to PROGRESS.md "Current Session State" when checkpointing:

```markdown
### Checkpoint [timestamp]
**Working on**: [specific task]
**Status**: [working|stuck|almost done]
**Current file**: [path]
**Last completed**: [what]
**Next action**: [specific next step]
**Blockers**: [any issues]
**Notes**: [anything else important]
```

## Test Isolation Mandate (dp-13)
Extension phpunit MUST run with `DB_DATABASE=paymenter_test`. The phpunit.xml `<php>` block enforces this; tests/bootstrap.php aborts if violated. See DECISIONS.md for rationale.
