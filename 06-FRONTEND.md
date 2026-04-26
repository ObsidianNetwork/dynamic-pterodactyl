# Frontend Architecture

## Overview

The customer-facing product-configuration UI uses Paymenter's native `dynamic_slider` config option type plus one core-owned Alpine coordinator: `resources/js/dynamic-slider-group.js`. The extension still does not ship its own JS bundle, but its reservation API is now called directly from Paymenter's checkout theme.

## Slider component

**File**: `themes/default/views/components/form/configoption.blade.php` (lines 79–248)

The slider is a native HTML `<input type="range">` wrapped in an Alpine.js component (`x-data`). Key characteristics:

- **Data shape**: The Alpine component holds `value` (current slider integer), `min`, `max`, `step`, and `displayValue` (formatted with a unit suffix). These are passed as Blade component props from the config option metadata.
- **Client-side price calculation**: `calculatePrice()` is a pure Alpine.js function that reads the pricing model from metadata (linear / tiered / base_addon) and computes the per-slider price delta locally. Price display updates instantaneously while a separate request manages capacity reservations.
- **Livewire entanglement**: The slider value is synced to Livewire via `$wire.entangle('{{ $name }}').live`. This allows the parent Livewire checkout component to re-render the cart total when the slider changes.
- **Reservation signalling**: after each debounced change, the child slider dispatches `slider-change` with `{ resourceType, value }` so the wrapper can submit a single reservation request for the whole product.

## `dynamicSliderGroup` Alpine coordinator

**File**: `resources/js/dynamic-slider-group.js`

This wrapper lives around the product checkout form only when the product contains at least one `dynamic_slider` config option.

- Holds shared state for all sliders on one product page: `memory`, `cpu`, `disk`, `token`, `error`, `loading`.
- Restores any prior token from `sessionStorage` using `dp_reservation_token_<productId>_<planId>`.
- Listens for child `slider-change` events, debounces 500ms, and POSTs `/api/dynamic-pterodactyl/reservation` with `product_id`, `plan_id`, `location_id`, and the full resource snapshot.
- Persists the latest token back to `sessionStorage` and mirrors it into `checkoutConfig.dp_reservation_token` before Add-to-cart.
- Treats `422` as a blocking capacity error, retries `429` with backoff, and degrades gracefully on `5xx`/network failures.

## Checkout persistence

Once Add-to-cart runs, the reservation token moves out of browser-only state and into `cart_items.checkout_config.dp_reservation_token`. That key is copied onto the created service during checkout and confirmed immediately by `App\Livewire\Cart::checkout()`.

## Accessibility gaps (tracked in dp-10)

- The live price display lacks `aria-live="polite"`. Screen readers do not announce price changes when the slider moves.
- Progress indicators in the admin panel (`dashboard.blade.php`, `node-monitoring.blade.php`) lack `role="progressbar"` and `aria-value*` attributes.

## Admin UI

The admin panel uses Filament v4 exclusively. The extension registers pages and resources via standard Filament discovery — no custom asset injection. See `05-ADMIN-UI.md` for the admin surface inventory.

## What this extension does NOT own on the frontend

- The `<input type="range">` HTML and Alpine.js component — owned by Paymenter core themes.
- The `calculateDynamicPrice()` method — in `app/Models/ConfigOption.php` (core).
- Cart total rendering — core Livewire Cart component.
- Checkout form — core Livewire Checkout component.
