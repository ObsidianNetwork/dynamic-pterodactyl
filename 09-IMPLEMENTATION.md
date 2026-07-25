# Implementation and Verification

## Runtime boundaries

- Paymenter 1.5.6, Filament 5, Livewire 4.
- Paymenter core owns pricing, cart/order/service persistence, and the built-in Pterodactyl provisioner.
- Dynamic Pterodactyl owns capacity reads, node selection, reservation identity, and lifecycle validation.
- Pterodactyl owns actual server allocation after a successful create.

## Required cross-repository changes

The Paymenter companion branch must include:

- transactional cart add/edit operations;
- atomic login ownership transfer;
- fail-closed checkout refresh and service binding;
- correct `ServiceConfig.slider_value` serialization;
- Pterodactyl `createServer()` begin/complete/fail integration;
- exact reserved-node and resource overrides;
- removal of browser reservation JavaScript.

The extension branch must include:

- checkout identity and provisioning migrations;
- `ReservationConfigurationService`;
- server-owned cart listeners;
- provisioning lifecycle methods;
- lowercase option creation/backfill;
- non-authoritative CPU semantics.

Deploy the Paymenter 1.5.6 baseline and extension migrations together. Migrating the extension without the companion core leaves no caller for service binding/provisioning consumption.

## Verification matrix

| Area | Required proof |
|---|---|
| Upgrade | Paymenter reports 1.5.6 dependency versions |
| Cart | reservation failure rolls back create and edit |
| Identity | resource, plan, location, quantity, currency, or price change changes fingerprint |
| Guest | cart owner and pending holds transfer atomically |
| Checkout | missing, expired, foreign, or mismatched hold rolls back order/service |
| Payment delay | hold expiry extends to invoice due time |
| Concurrency | duplicate cart hold and duplicate provisioning worker are rejected |
| Node binding | outbound Pterodactyl request uses the stored node and limits |
| Retry | existing external server consumes a pending row idempotently |
| Serialization | slider value reaches lowercase service properties and `getServiceProperties()` |
| CPU | changing CPU never rejects a node solely on fabricated capacity |

Run the Paymenter PHPUnit suite, the extension PHPUnit suite against a dedicated test database, frontend lint/build, and migration up/down checks. The extension test bootstrap must retain its test-database guard.

## Operational checks

After deployment:

1. Run migrations.
2. Verify legacy pending token-flow rows were cancelled with the migration note.
3. Confirm all configured resource/location option keys are lowercase.
4. Inspect one guest-login checkout through paid provisioning.
5. Verify reservation `node_id` equals the created Pterodactyl server node.
6. Verify confirmed rows have `consumed_at` and no active provisioning lease.
