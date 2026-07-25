# Dynamic Pterodactyl contributor guide

Read `README.md`, `DECISIONS.md`, and the nearest `AGENTS.md` before changing
this extension. The July 2026 decisions supersede older browser-reservation,
Filament 4, static-slider, and non-authoritative CPU designs.

## Current contract

- Paymenter 1.5.6, Filament 5, Livewire 4, PHP 8.3/8.4.
- Pterodactyl Panel and Wings 1.12.3 or newer.
- Paymenter core owns slider rendering and pricing.
- This extension owns live complete-vector stock, reservations, CPU policy,
  allocation claims, and capacity-aware upgrade commitments.
- The built-in Paymenter Pterodactyl extension owns server API mutations.
- Dynamic products fail closed when authoritative stock is unavailable.
- Customer quote responses never expose raw node identity.
- Cart holds last 15 minutes; checkout guarantees capacity for exactly seven
  days; successful payment creates a non-expiring `paid_committed` commitment.
- Enabled node-capacity policies dedicate those nodes to the reservation-backed
  lifecycle. External panel administrators and automation must not mutate them.
- Quantity is one. Upgrades reserve positive deltas on the existing node.

## Safety rules

- Never cache Pterodactyl stock responses.
- Never restore browser reservation tokens or customer reservation endpoints.
- Never calculate independent RAM/CPU/disk maxima; quote the complete resource
  vector against one eligible node and its exact free allocations.
- Never infer immutable panel, server, user, node, egg, or allocation identity
  for legacy commitments.
- Never mark a capacity-backed invoice paid outside the atomic payment
  coordinator.
- Never mark a service active until the external server and allocation set
  match its signed commitment.
- Keep Paymenter and this extension's remediation branches deployable together.

## Required checks

Run Paymenter's PHP 8.3/8.4 SQLite and MariaDB 11/12 matrix, the extension's
cross-repository matrix, JavaScript stock tests, migration readiness, and a real
Pterodactyl staging canary. Deploy in maintenance mode: core migrations first,
then extension migrations/readiness, restart queue workers, and only then leave
maintenance mode.
