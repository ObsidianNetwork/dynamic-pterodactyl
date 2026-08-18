# Implementation and Verification

## Runtime boundaries

- Paymenter 1.5.7, Filament 5, Livewire 4.
- Paymenter core owns pricing, cart/order/service persistence, and the built-in Pterodactyl provisioner.
- Dynamic Pterodactyl owns capacity reads, node selection, reservation identity, and lifecycle validation.
- Pterodactyl Panel and Wings 1.12.3+ own actual server state after a verified
  create or build update.

## Required cross-repository changes

The Paymenter companion branch must include:

- transactional cart add/edit operations;
- atomic login ownership transfer;
- fail-closed checkout refresh and service binding;
- correct `ServiceConfig.slider_value` serialization;
- Pterodactyl `createServer()` begin/complete/fail integration;
- exact reserved-node and resource overrides;
- exact allocation-ID create and lifecycle reconciliation;
- guest-safe complete-vector quote JavaScript that fails checkout closed;
- durable payment, cancellation, retry, and capacity-aware upgrade state
  machines.

The extension branch must include:

- checkout identity and provisioning migrations;
- `ReservationConfigurationService`;
- server-owned cart listeners;
- provisioning lifecycle methods;
- lowercase option creation/backfill;
- per-node authoritative CPU policy and overcommit;
- exact allocation claims and fixed-node upgrade deltas;
- legacy readiness and forward-only migration gates.

Deploy the Paymenter 1.5.7 baseline and extension migrations together. Migrating the extension without the companion core leaves no caller for service binding/provisioning consumption.

## Verification matrix

| Area | Required proof |
|---|---|
| Upgrade | Paymenter reports 1.5.7 dependency versions |
| Cart | reservation failure rolls back create and edit |
| Identity | resource, plan, location, quantity, currency, or price change changes fingerprint |
| Guest | cart owner and pending holds transfer atomically |
| Checkout | missing, expired, foreign, or mismatched hold rolls back order/service |
| Payment delay | hold expiry extends to invoice due time |
| Paid state | payment creates non-expiring capacity and service remains provisioning |
| Concurrency | duplicate cart hold and duplicate provisioning worker are rejected |
| Node binding | outbound request uses stored node, limits, and allocation IDs |
| Retry | existing external server consumes a pending row idempotently |
| Serialization | slider value reaches lowercase service properties and `getServiceProperties()` |
| CPU | configured physical capacity, overcommit, live server limits, and holds produce the exact bound |
| Bounds | 32 GiB configured / 23 GiB feasible clamps to 23; 100 GiB feasible keeps 32 |
| Upgrade | fixed-node positive delta, immutable source/target, payment, PATCH, and reconciliation |
| Cancellation | exact external absence precedes capacity and product-stock release |
| Ports | quote, hold, create, retry, and cancellation preserve exact allocation identity |

Run the Paymenter PHPUnit suite, the extension PHPUnit suite against a dedicated test database, frontend lint/build, and migration up/down checks. The extension test bootstrap must retain its test-database guard.

## Operational checks

After deployment:

1. Back up both databases and enter Paymenter maintenance mode.
2. Deploy/migrate Paymenter core, then run the extension migration/readiness
   command. Do not leave maintenance if schema activation or readiness fails.
3. Restart queue workers and verify the scheduler and failed-job monitoring.
4. Confirm Panel and Wings are 1.12.3 or newer, API credentials have node,
   server, user, location, nest/egg, and allocation access, and panel URLs
   canonicalize to the same identity.
5. Reconcile every legacy commitment reported by the readiness gate.
6. Configure and enable a CPU policy for each dedicated dynamic-stock node.
7. Confirm all configured resource/location keys are lowercase and quantity is
   disabled.
8. Run a real-panel guest checkout canary for 32/23 and 32/100 bounds, payment,
   queue retry, exact resources/ports, cancellation, and a paid upgrade.
9. Verify reservation `node_id`, resources, owner, nest/egg, and allocation IDs
   exactly equal the Pterodactyl server.
10. Verify confirmed rows have `consumed_at`, no active lease, and that external
    panel/automation writes are disabled on managed nodes.
