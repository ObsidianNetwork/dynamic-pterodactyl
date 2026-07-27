# ADMIN KNOWLEDGE BASE

## OVERVIEW

Filament 5 administration surface for Dynamic Pterodactyl. Standalone `Page` classes own dashboard-style screens and bind to 1:1 Blade views; `Resource` classes own model tables/forms and delegate state-changing actions to services.

## STRUCTURE

```text
Admin/
├── Pages/                         # Dashboard, SetupWizard, NodeMonitoring, AuditLogPage
└── Resources/
    ├── ReservationResource.php    # read/action-only reservations table
    ├── ReservationResource/Pages/ListReservations.php
    ├── AlertConfigResource.php    # alert config CRUD + test action
    └── AlertConfigResource/Pages/  # Create/Edit/List page classes
```

Paired views live outside this directory at `../resources/views/admin/`.

## WHERE TO LOOK

| Task | Start at | Supporting context |
|---|---|---|
| Admin landing metrics | `Pages/Dashboard.php` | `../resources/views/admin/dashboard.blade.php` |
| Product slider setup wizard | `Pages/SetupWizard.php` | `../Services/ConfigOptionSetupService.php`, `../resources/views/admin/setup-wizard.blade.php` |
| Live node capacity screen | `Pages/NodeMonitoring.php` | `../Services/ResourceCalculationService.php`, `../resources/views/admin/node-monitoring.blade.php` |
| Audit and delivery failures | `Pages/AuditLogPage.php` | `../Models/AuditLog.php`, `../Models/AlertDeliveryLog.php`, `../resources/views/admin/audit-log.blade.php` |
| Reservation admin actions | `Resources/ReservationResource.php` | `Resources/ReservationResource/Pages/ListReservations.php`, `../Services/ReservationService.php` |
| Alert config CRUD/actions | `Resources/AlertConfigResource.php` | `Resources/AlertConfigResource/Pages/`, `../Services/AlertService.php` |

## CONVENTIONS

- Standalone screens extend `Filament\Pages\Page`; resources extend `Filament\Resources\Resource` and register nested page classes through `getPages()`.
- Page views are explicitly namespaced: `dynamic-pterodactyl::admin.dashboard`, `setup-wizard`, `node-monitoring`, and `audit-log`; keep PHP page names and Blade filenames paired 1:1.
- Resource page classes stay depth-four under `Resources/<ResourceName>/Pages/` and only point `protected static string $resource` back to the resource unless header actions are needed.
- Use Filament 5 APIs: `Schema $schema`, schema layouts from `Filament\Schemas\Components\*`, inputs from `Filament\Forms\Components\*`, table `recordActions()`, and top-level `Filament\Actions\*` imports.
- `SetupWizard` may be large, but persistence belongs in `ConfigOptionSetupService::createDynamicSliderOptions()`; the page gathers state, validates wizard flow, warns on existing options, and reports setup results.
- `ReservationResource` is not a create surface: `canCreate()` returns `false`; extend/cancel/cleanup actions call `ReservationService` with the authenticated actor.
- `AlertConfigResource` owns CRUD form/table composition; test notifications call `AlertService::sendTestNotification()`.
- Alert config creates/updates/deletes have observer side effects through `../Models/Observers/AlertConfigObserver.php`; expect audit writes in addition to resource saves.
- Keep navigation under the `Dynamic Pterodactyl` group and preserve explicit sort order for predictable admin menus.

## ANTI-PATTERNS (ADMIN)

- Do not add standalone Blade views without a matching `Page` class and `protected string $view` binding.
- Do not put reservation mutations directly in table actions; route them through `ReservationService`.
- Do not make reservations manually creatable from Filament.
- Do not bypass `ConfigOptionSetupService` from the setup wizard to write core config options inline.
- Do not treat `AlertConfigResource` saves as UI-only changes; observer and alert-service side effects are part of the contract.
- Do not mix legacy Filament v3/v4 resource APIs into these Filament 5 resources.
