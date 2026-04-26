# Admin Interface

> **Related docs**: [02-SERVICES.md](02-SERVICES.md) (services used), [01-DATABASE.md](01-DATABASE.md) (models)

Built with [Filament](https://filamentphp.com/docs/4.x/) for a modern admin experience.

---

## Navigation Structure

```
Dynamic Pterodactyl (Admin Menu)
├── Dashboard            → Capacity overview and node status
├── Setup Wizard         → Atomic product and pricing configuration
├── Node Monitoring      → Real-time node metrics and utilization
├── Reservations         → Read-only reservation list and manual actions
├── Alerts               → CRUD for capacity alert thresholds
└── Audit Log            → Full audit log table and delivery failures
```

---

## Dashboard Page

- **Purpose**: Provides a high-level overview of system health, connection status, and aggregate capacity across all locations.
- **Who uses it**: Administrators monitoring the overall health of the extension.
- **Primary data source**: `ResourceCalculationService` (live Pterodactyl API) and `ReservationService` (local DB).

## Setup Wizard

- **Purpose**: A multi-step configuration tool for setting up product resource sliders and pricing rules. It supersedes both the old pricing config resource pages and the standalone `Settings` page.
- **Who uses it**: Administrators onboarding new products or updating existing pricing models.
- **Primary data source**: Paymenter core `ConfigOption` and `Plan` models.

## Node Monitoring Page

- **Purpose**: Displays real-time metrics for each individual node, including memory, CPU, and disk utilization.
- **Who uses it**: Administrators performing deep-dive diagnostics on specific hardware nodes.
- **Primary data source**: `ResourceCalculationService` (live Pterodactyl API).

## Audit Log Page
- **Purpose**: Displays a searchable table of all configuration changes and system events, including recent alert-delivery failures.
- **Who uses it**: Administrators auditing system changes or troubleshooting alert delivery.
- **Primary data source**: `ptero_audit_logs` table.

## Alert Config Resource

- **Purpose**: CRUD interface for managing capacity alert thresholds (memory/disk) and notification channels (email/webhook).
- **Who uses it**: Administrators configuring proactive monitoring for resource exhaustion.
- **Primary data source**: `ptero_alert_configs` table.

## Reservation Resource

- **Purpose**: A read-only list of all active and historical resource reservations, with actions to manually extend or cancel pending holds.
- **Who uses it**: Administrators managing customer reservations or performing manual cleanup.
- **Primary data source**: `ptero_resource_reservations` table.
