# Allocation and Concurrency Algorithms

## Availability

For memory and disk:

`available = effective node total - Pterodactyl server allocations - live pending holds`

Effective totals apply Pterodactyl's memory/disk overallocation percentage. Pending means `status = pending` and `expires_at > now()`.

CPU is not hard inventory because the Pterodactyl application API exposes server CPU limits but no authoritative node CPU capacity. CPU remains part of the immutable purchase and provisioned server limit, while availability returns null and `cpu_capacity_enforced = false`.

## Node selection

1. Fetch live nodes for the chosen location.
2. Subtract unexpired pending holds.
3. Reject maintenance nodes.
4. Reject nodes that cannot fit memory or disk.
5. Score remaining relative headroom:
   - memory: 60%;
   - disk: 40%.
6. Choose the highest score.

When replacing a cart hold, the previous token is excluded from availability calculation and the old row is cancelled in the same transaction.

## Concurrency

`reserveForCartItem()` locks pending rows for the location before selecting and inserting. A generated unique active-cart-item column prevents two live holds for one cart item.

`beginProvisioning()` locks the service-bound reservation. A five-minute `provisioning_started_at` lease rejects a second worker. The hold expiry is extended through the lease so scheduled cleanup cannot release capacity during the Pterodactyl request.

The row stays pending until the external create succeeds. This closes the gap where a confirmed row stopped counting before a server existed. After success, Pterodactyl is the allocated-capacity source and the reservation becomes confirmed.

Pterodactyl `external_id = service.id` provides reconciliation on retry: an existing server completes the bound reservation instead of creating another.
