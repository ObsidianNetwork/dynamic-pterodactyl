# Database Schema

The extension owns seven live tables:

| Table | Purpose |
|---|---|
| `ptero_resource_reservations` | Authoritative cart-to-provisioning capacity holds |
| `ptero_reservation_allocations` | Exact primary and additional Pterodactyl allocation claims |
| `ptero_node_capacity_policies` | Authoritative physical CPU and overcommit policy per panel/node |
| `ptero_capacity_scopes` | Database rows used to serialize stock and policy mutations per panel/location |
| `ptero_audit_logs` | Extension action history |
| `ptero_alert_configs` | Per-location alert thresholds |
| `ptero_alert_delivery_log` | Alert delivery outcomes |

`ptero_pricing_configs` is retired. Paymenter `config_options.metadata` is the pricing source of truth.

## Resource reservations

The base table is created by `2025_01_01_000001_create_ptero_resource_reservations_table.php`. The server-owned checkout identity is added by `2026_07_25_000001_add_checkout_identity_to_ptero_resource_reservations.php`.

### Identity

| Column | Meaning |
|---|---|
| `cart_item_id`, `cart_id` | Original cart identity; the item FK becomes null when checkout clears the cart |
| `server_extension_id`, `panel_identity` | Paymenter provisioner identity and SHA-256 of its normalized panel URL |
| `service_id`, `user_id` | Assigned atomically at checkout/login |
| `product_id`, `plan_id`, `quantity`, `currency_code` | Purchase identity |
| `configuration_payload` | Canonical immutable checkout snapshot, including customer, cart, server extension, hashed panel identity, node, and selected options |
| `configuration_fingerprint` | SHA-256 of the canonical payload |
| `pricing_version`, `formula_version`, `calculated_price` | Pricing provenance |
| `node_id`, `location_id`, `memory`, `cpu`, `disk` | Reserved placement and authoritative limits |
| `purpose`, `service_upgrade_id`, `reserved_*` | Checkout or fixed-node upgrade identity and the positive upgrade delta |
| `guaranteed_until`, `paid_committed_at` | Seven-day invoice deadline and non-expiring post-payment commitment |
| `external_server_*`, `external_user_id`, `nest_id`, `egg_id` | Durable Pterodactyl lifecycle identity |

The opaque `token` remains for internal/admin lookup of legacy rows. It is not a customer bearer credential and is never placed in browser, URL, cart, or service state.

### Lifecycle

```mermaid
stateDiagram-v2
    [*] --> pending: cart create or edit
    pending --> pending: login or seven-day checkout bind
    pending --> paid_committed: invoice payment
    paid_committed --> confirmed: exact Pterodactyl state verified
    pending --> expired: hold deadline passes
    pending --> cancelled: cart removal or replacement
    confirmed --> cancelled: external absence verified
```

`provisioning_started_at` and the unguessable `provisioning_lease_id`
prevent concurrent or stale workers from consuming the same commitment.
`consumed_at` records verified Pterodactyl creation or upgrade. A failed
attempt clears only its matching lease and records
`last_provisioning_error`; paid capacity remains committed for retry and
operator reconciliation.

Database-specific generated columns or partial indexes enforce one live
checkout hold per cart item, one live checkout commitment per service, and one
live upgrade commitment per service upgrade.

During migration, legacy pending rows without a configuration fingerprint are cancelled. They came from the retired browser/listener token flows and cannot be proved safe. Active carts acquire a fresh server-owned hold on edit or checkout.

## Units

- Memory and disk: MiB-compatible integer values used by Pterodactyl.
- CPU: Pterodactyl percentage (`100` = one logical core). Effective node stock
  is `physical percentage × overcommit basis points / 10,000`.
- Money: decimal snapshot in the cart currency.

## Rollback

The key-normalization migration is intentionally irreversible: restoring uppercase `MEMORY`, `CPU`, `DISK`, or `LOCATION` keys would make the built-in provisioner ignore slider selections.
