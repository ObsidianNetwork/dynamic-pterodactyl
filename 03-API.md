# API Surface

All routes are registered from `routes/api.php`.

## Customer quote routes

Prefix: `/api/dynamic-pterodactyl`

| Method | Route | Response |
|---|---|---|
| POST | `/products/{product}/resource-quote` | Guest-safe, complete-vector checkout bounds; `web`, `throttle:30,1` |
| POST | `/services/{service}/upgrade-quote` | Customer-owned, fixed-node upgrade bounds; `web`, `auth`, `throttle:30,1` |

The quote responses expose no node identity. Each bound is feasible for the
complete RAM/CPU/disk/allocation vector, rather than an independent cluster
maximum that might combine different nodes.

## Admin routes

Prefix: `/api/dynamic-pterodactyl/admin`
Middleware: `web`, `auth`, `EnsureUserIsAdmin`, `throttle:30,1`

| Method | Route | Purpose |
|---|---|---|
| GET | `/reservations` | Paginated reservation inventory |
| POST | `/reservations/{token}/cancel` | Cancel an unbound pending row with a reason |
| GET | `/capacity` | Cluster capacity summary |
| GET | `/availability/{locationId}/nodes` | Raw per-node details |

## Removed customer reservation and legacy preview APIs

There is no customer create/get/cancel/extend reservation endpoint. Capacity holds are created synchronously by server-side cart listeners. This removes browser idempotency, bearer tokens, session storage, URL state, and client-selected node ownership from the protocol.

The retired `/availability/{locationId}`, `/pricing/calculate`, and
`/pricing/config/{productId}` routes are also absent. Checkout pricing is owned
by Paymenter core, and live stock is available only through the complete-vector
quote contracts above.

Controllers validate and authorize, then delegate to services. Customer errors must never include raw upstream Pterodactyl bodies or node details.
