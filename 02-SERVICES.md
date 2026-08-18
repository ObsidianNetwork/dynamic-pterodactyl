# Core Services

| Service | Responsibility |
|---|---|
| `ReservationConfigurationService` | Build canonical checkout payloads, fingerprints, and service-match proofs |
| `ReservationService` | Own the hold from cart creation through provisioning |
| `PterodactylInventoryService` | Read paginated Pterodactyl 1.12.3+ node, server, location, and allocation inventory |
| `ResourceCalculationService` | Overlay live RAM/disk/CPU/port inventory with local commitments |
| `NodeSelectionService` | Select one public, non-maintenance node that fits the complete vector |
| `ConfigOptionSetupService` | Transactionally create normalized native slider options |
| `ResourceQuoteService` | Compute customer-safe, complete-vector live bounds |
| `UpgradeReservationService` | Quote, reserve, and fulfill fixed-node resource upgrades |
| `AllocationSelectionService` | Deterministically select the exact primary/additional ports used by quotes and reservations |
| `LegacyReservationReadinessService` | Fail extension migration over unresolved legacy lifecycle identity without inferring values |
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
| `commitPaidService()` | Convert the seven-day invoice guarantee to non-expiring paid capacity |
| `beginProvisioning()` | Lock, validate, and lease the paid commitment |
| `completeProvisioning()` | Consume it only after Pterodactyl accepts the server |
| `failProvisioning()` | Clear the attempt lease while retaining capacity |
| `cancelForCartItem()` | Release an unbound hold before cart-item deletion |

All state-changing paths use transactions and row locks. Customer input never supplies a reservation token or node.

## ResourceCalculationService

RAM, disk, and CPU capacity are computed as:

`effective total - existing Pterodactyl limits - live local commitments`

Memory and disk honor finite Pterodactyl overallocation. CPU uses an enabled,
panel- and node-bound `NodeCapacityPolicy`; the physical value is expressed in
Pterodactyl percentage and multiplied by its configured overcommit ratio.
Nodes with missing/mismatched CPU policy, unlimited existing server limits,
private/maintenance state, unsafe client allocation headroom, or no feasible
port are excluded.

The service also reserves exact allocation IDs. A confirmed commitment remains
in the local overlay until the independently-read Pterodactyl server snapshot
proves the pinned identity and complete target vector, preventing a
read-after-write oversell window.

## NodeSelectionService

Candidates must fit RAM, CPU, disk, and the requested allocation contract on
one eligible node in the selected location. Remaining relative headroom is
scored 50% RAM, 15% CPU, and 35% disk; node ID is the deterministic tie-breaker.

## Configuration setup

Native option environment keys are lowercase: `memory`, `cpu`, `disk`, and `location`. The normalization migration updates existing options and service properties. `ExtensionHelper::getServiceProperties()` serializes `ServiceConfig.slider_value` for dynamic sliders so a config row cannot overwrite the numeric property with null.
