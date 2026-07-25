# API Surface

All routes are registered from `routes/api.php`.

## Authenticated customer routes

Prefix: `/api/dynamic-pterodactyl`
Middleware: `web`, `auth`, `throttle:30,1`

| Method | Route | Response |
|---|---|---|
| GET | `/availability/{locationId}` | Aggregate location maxima; no node identity |
| POST | `/pricing/calculate` | Core-owned dynamic-slider price preview |
| GET | `/pricing/config/{productId}` | Native slider configuration |

Availability responses set `max_cpu` and `resource_capacity.cpu` to `null` and expose `cpu_capacity_enforced: false`. `has_capacity` uses memory and disk only.

## Admin routes

Prefix: `/api/dynamic-pterodactyl/admin`
Middleware: `web`, `auth`, `EnsureUserIsAdmin`, `throttle:30,1`

| Method | Route | Purpose |
|---|---|---|
| GET | `/reservations` | Paginated reservation inventory |
| POST | `/reservations/{token}/cancel` | Cancel an unbound pending row with a reason |
| GET | `/capacity` | Cluster capacity summary |
| GET | `/availability/{locationId}/nodes` | Raw per-node details |

## Removed customer reservation API

There is no customer create/get/cancel/extend reservation endpoint. Capacity holds are created synchronously by server-side cart listeners. This removes browser idempotency, bearer tokens, session storage, URL state, and client-selected node ownership from the protocol.

Controllers validate and authorize, then delegate to services. Customer errors must never include raw upstream Pterodactyl bodies or node details.
