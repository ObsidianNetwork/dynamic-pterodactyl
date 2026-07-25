# Frontend Architecture

Paymenter core owns the native `dynamic_slider` input, price preview, Livewire state, and checkout form.

The slider is a native range input with Alpine state and Livewire entanglement.
It supports Page Up/Down, Home/End, an accessible value description, a live
price output, and live complete-vector stock bounds.

The browser does not create or store reservations. There is:

- no reservation fetch request;
- no reservation token in session storage;
- no token mirrored into Livewire checkout configuration;
- no token in URL state;
- no client-selected node.

The companion Paymenter theme calls the guest-safe product quote endpoint on
initial load and after location or resource changes. Requests are debounced,
older responses are aborted and ignored, and checkout remains disabled while
stock is loading or unavailable. Every returned maximum is step-aligned and
conditional on the other selected resources fitting the same eligible node.
For example, a configured 32 GiB maximum becomes 23 GiB when the best feasible
node has 23 GiB left, but remains 32 GiB when at least 32 GiB is feasible.

The browser is advisory. Cart create/edit repeats strict range, step,
complete-vector, CPU, and allocation validation under database locks. A
failure is surfaced through Paymenter's `DisplayException` handling and the
cart mutation is rolled back.

Pricing preview remains client-friendly, but Paymenter core recalculates the authoritative price when building the cart and service.

Capacity-aware upgrades use the same component with an authenticated,
customer-owned fixed-node quote. Admin pages use Filament 5 and extension Blade
views.
