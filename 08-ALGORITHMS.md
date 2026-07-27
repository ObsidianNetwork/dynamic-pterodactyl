# Allocation and Concurrency Algorithms

## Availability

For RAM, CPU, and disk:

`available = effective node total - Pterodactyl server limits - live local commitments`

Effective RAM/disk totals apply Pterodactyl's finite overallocation percentage.
Effective CPU is the administrator-declared physical percentage multiplied by
the per-node basis-point overcommit ratio. A missing or stale CPU policy makes
the node ineligible; CPU is never inferred from a fabricated default.

Live commitments include unexpired `pending` checkout/upgrade holds,
non-expiring `paid_committed` rows, and confirmed expectations until live
Pterodactyl inventory proves the exact external target. Upgrade rows reserve
only each positive resource delta while retaining the full immutable target.

Unassigned Pterodactyl allocation IDs are filtered by local allocation claims.
Required ports, allowed primary-port ranges, and dedicated-IP grouping are
evaluated by the same deterministic selector during quote and reservation.

## Node selection

1. Fetch live nodes for the chosen location.
2. Overlay live local resource and allocation claims.
3. Reject private, maintenance, unbounded, unmanaged-CPU, unsafe-allocation,
   or port-infeasible nodes.
4. Reject nodes that cannot fit the complete RAM/CPU/disk vector.
5. Score remaining relative headroom:
   - memory: 50%;
   - CPU: 15%;
   - disk: 35%.
6. Choose the highest score, then the lowest node ID on a tie.

When replacing a cart hold, the previous token is excluded from availability calculation and the old row is cancelled in the same transaction.

## Concurrency

`reserveForCartItem()` locks the panel/location capacity-scope row before
reading inventory, selecting allocations, and inserting. Database-specific
unique guards prevent duplicate live cart, checkout-service, and upgrade rows.

Checkout extends the cart hold to exactly seven days. Payment changes it to
`paid_committed`, which has no capacity expiry. `beginProvisioning()` locks the
service-bound commitment; an unguessable lease rejects concurrent or stale
workers.

The row stays `paid_committed` until the external create and allocation set are
verified. After confirmation, a local expectation overlay remains until the
separately-read Pterodactyl inventory catches up.

Pterodactyl `external_id = service.id` initiates reconciliation on retry, but
is never sufficient proof. Numeric server/user IDs, UUID, identifier, panel,
node, nest, egg, resource limits, owner external ID, and allocation IDs must
all match the immutable commitment.
