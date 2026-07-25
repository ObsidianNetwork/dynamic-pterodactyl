# Checkout and Provisioning Lifecycle

The reservation is server-owned from cart mutation through Pterodactyl creation.

```mermaid
sequenceDiagram
    participant Cart
    participant Hold as ReservationService
    participant Pay as Paymenter payment
    participant Ptero as Pterodactyl provisioner

    Cart->>Hold: reserveForCartItem
    Cart->>Hold: transferCartOwnership on login
    Cart->>Hold: bind seven-day invoice guarantee
    Pay->>Hold: commit paid capacity
    Pay->>Ptero: dispatch CreateJob
    Ptero->>Hold: beginProvisioning
    Ptero->>Ptero: create exact node, limits, and ports
    Ptero->>Hold: completeProvisioning
```

## Cart create and update

`CartItem\Created` and `CartItem\Updated` use `CartItemCreatedListener`. The listener delegates to `reserveForCartItem()` and intentionally lets errors bubble. Paymenter wraps `Cart::add()` and quantity edits in a database transaction, so insufficient capacity or an unavailable extension rolls back the cart mutation.

Changing plan, resources, quantity, location, currency, or price changes the canonical fingerprint. The old pending row is cancelled and replaced under a location lock. An exact retry refreshes the existing hold.

## Guest login

`UserAuthListener` updates the cart owner and calls `transferCartOwnership()` in one database transaction. Existing non-null ownership must match; it is never silently overwritten.

## Checkout

Paymenter refreshes each dynamic hold before creating orders. After the service and its config/property rows exist, `bindCartItemToService()`:

- proves the current cart snapshot matches the stored fingerprint;
- proves product, plan, quantity, and currency match the service;
- transfers a guest owner if needed;
- assigns `service_id`;
- extends expiry through the invoice due time.

Missing extension, missing hold, expired hold, or identity drift aborts and rolls back checkout.

## Cart removal

`CartItem\Deleting` calls `cancelForCartItem()` while the relationship still exists. Bound checkout rows are not cancelled. Clearing the cart after checkout nulls the cart-item FK but preserves `cart_id` and `service_id`.

## Payment and provisioning

Payment processing atomically turns the expiring invoice guarantee into a
non-expiring `paid_committed` reservation and moves the service to
`provisioning`. It then dispatches the retryable create job after commit.

The built-in Pterodactyl `createServer()` calls `beginProvisioning()` directly.
The returned context overrides the actual lowercase `node`, `location`,
`memory`, `cpu`, and `disk` settings plus the exact primary/additional
allocation IDs. The service remains `provisioning` and the row remains
`paid_committed` while the external request runs.

After the server and complete allocation set are re-read and match,
`completeProvisioning()` marks the reservation confirmed, records durable
server/user identity and `consumed_at`, then activates the service. Failure
clears only the matching lease. If the server exists on retry, the provisioner
reconciles it instead of duplicating it.

Cancellation shares the same per-service queue overlap key. Capacity and
product stock are released only after the exact numeric Pterodactyl server ID
is proved absent.
