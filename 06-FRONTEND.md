# Frontend Architecture

## Overview

The customer-facing product-configuration UI uses Paymenter's native `dynamic_slider` config option type. The extension does not inject its own frontend assets or JavaScript libraries. All slider rendering, interaction, and client-side price calculation happen within the core Paymenter theme.

## Slider component

**File**: `themes/default/views/components/form/configoption.blade.php` (lines 79–248)

The slider is a native HTML `<input type="range">` wrapped in an Alpine.js component (`x-data`). Key characteristics:

- **Data shape**: The Alpine component holds `value` (current slider integer), `min`, `max`, `step`, and `displayValue` (formatted with a unit suffix). These are passed as Blade component props from the config option metadata.
- **Client-side price calculation**: `calculatePrice()` is a pure Alpine.js function that reads the pricing model from metadata (linear / tiered / base_addon) and computes the per-slider price delta locally. No network request is made on slider drag. Price display updates instantaneously.
- **Livewire entanglement**: The slider value is synced to Livewire via `$wire.entangle('{{ $name }}').live`. This allows the parent Livewire checkout component to re-render the cart total when the slider changes.
- **Known issue (dp-10)**: The `x-model` binding fires a Livewire request on every `input` event, including continuous drag. This causes server load and UI stutter. Fix tracked in dp-10 (debounce to `.300ms` on `x-model`).

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
