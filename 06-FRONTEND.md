# Frontend Architecture

Paymenter core owns the native `dynamic_slider` input, price preview, Livewire state, and checkout form.

The slider is a native range input with Alpine state and Livewire entanglement. It supports Page Up/Down, Home/End, an accessible value description, a live price output, and server-side validation of min/max values.

The browser does not create or store reservations. There is:

- no reservation fetch request;
- no reservation token in session storage;
- no token mirrored into Livewire checkout configuration;
- no token in URL state;
- no client-selected node.

Capacity is checked synchronously when the configured product is added or edited in the cart. A failure is surfaced through Paymenter's `DisplayException` notification handling and the cart mutation is rolled back.

Pricing preview remains client-friendly, but Paymenter core recalculates the authoritative price when building the cart and service.

The extension ships no customer JavaScript bundle. Admin pages use Filament 5 and extension Blade views.
