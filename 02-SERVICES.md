# Core Services

| Service | Responsibility |
|---|---|
| `ReservationConfigurationService` | Build canonical checkout payloads, fingerprints, and service-match proofs |
| `ReservationService` | Own the hold from cart creation through provisioning |
| `ResourceCalculationService` | Read live Pterodactyl memory/disk inventory and pending holds |
| `NodeSelectionService` | Select a non-maintenance node that fits authoritative resources |
| `ConfigOptionSetupService` | Transactionally create normalized native slider options |
| `SliderConfigReaderService` | Read native slider metadata for price previews |
| `AlertService` | Capacity and shortfall notifications |
| `AuditLogService` | Best-effort extension audit writes |

## ReservationConfigurationService

`forCartItem()` rejects partial configurations and quantity greater than one, resolves the selected Pterodactyl location, and records:

- cart, product, plan, quantity, and currency;
- memory, CPU limit, disk, and location;
- all selected config-option values and metadata;
- the exact calculated cart price;
- pricing and formula versions.

Every RAM, CPU, and disk value comes from either its validated slider selection or the product's static Pterodactyl setting; a missing value fails closed. After node selection, `withNode()` adds the node and `fingerprint()` hashes the canonical JSON. Guest login uses `withCustomer()` while holding the cart and reservation locks, then stores the replacement fingerprint in the same transaction. `assertServiceMatches()` proves the created service still has the same customer, purchase, and resource values before provisioning.

## ReservationService

The primary methods are:

| Method | Boundary |
|---|---|
| `reserveForCartItem()` | Create, reuse, or atomically replace one cart hold |
| `transferCartOwnership()` | Move guest holds with the cart during login |
| `bindCartItemToService()` | Attach the matching hold and extend it through invoice due time |
| `beginProvisioning()` | Lock, validate, and lease the pending hold |
| `completeProvisioning()` | Consume it only after Pterodactyl accepts the server |
| `failProvisioning()` | Clear the attempt lease while retaining capacity |
| `cancelForCartItem()` | Release an unbound hold before cart-item deletion |

All state-changing paths use transactions and row locks. Customer input never supplies a reservation token or node.

## ResourceCalculationService

Memory and disk capacity are computed as:

`effective total - existing server allocation - unexpired pending reservations`

Memory and disk honor Pterodactyl overallocation. Reads are uncached and the cluster snapshot path batches node/server data.

Pterodactyl does not expose authoritative node CPU capacity. Therefore:

- `total.cpu` and `available.cpu` are `null`;
- `cpu_capacity_enforced` is `false`;
- CPU allocations/reservations remain visible for reporting;
- CPU never rejects or scores a node;
- the selected CPU value is still fingerprinted and passed as the server limit.

## NodeSelectionService

Candidates must be outside maintenance mode and fit memory and disk. The score is 60% relative memory headroom and 40% relative disk headroom. CPU is deliberately excluded.

## Configuration setup

Native option environment keys are lowercase: `memory`, `cpu`, `disk`, and `location`. The normalization migration updates existing options and service properties. `ExtensionHelper::getServiceProperties()` serializes `ServiceConfig.slider_value` for dynamic sliders so a config row cannot overwrite the numeric property with null.
