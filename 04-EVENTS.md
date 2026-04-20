# Event Hook Integration

> **Related docs**: [02-SERVICES.md](02-SERVICES.md) (services called by handlers), [README.md](README.md) (system flow)

---

## Overview

The companion extension integrates with Paymenter's event system to:
1. Create reservations when items are added to cart
2. Cancel reservations when items are removed
3. Confirm reservations when payment succeeds
4. Track service creation

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Cart Item  │────▶│  Checkout   │────▶│   Invoice   │────▶│   Service   │
│   Created   │     │   (wait)    │     │    Paid     │     │   Created   │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
       │                                       │                   │
       ▼                                       ▼                   ▼
 ┌───────────┐                          ┌───────────┐       ┌───────────┐
 │  Create   │                          │  Verify & │       │   Link    │
 │Reservation│                          │  Confirm  │       │ Service   │
 │ (15 min)  │                          │Reservation│       │    ID     │
 └───────────┘                          └───────────┘       └───────────┘
```

---

## Event Listener Registration

In the extension's `boot()` method:

```php
<?php
// In DynamicPterodactyl.php

public function boot()
{
    // ... other boot tasks ...
    
    $this->registerEventListeners();
}

private function registerEventListeners(): void
{
    // Cart item created - create reservation
    Event::listen(\App\Events\CartItem\Created::class, function ($event) {
        $this->handleCartItemCreated($event);
    });
    
    // Cart item deleted - cancel reservation
    Event::listen(\App\Events\CartItem\Deleted::class, function ($event) {
        $this->handleCartItemDeleted($event);
    });
    
    // Invoice paid - confirm reservation
    Event::listen(\App\Events\Invoice\Updated::class, function ($event) {
        $this->handleInvoiceUpdated($event);
    });
    
    // Service created - link reservation
    Event::listen(\App\Events\Service\Created::class, function ($event) {
        $this->handleServiceCreated($event);
    });
}
```

---

## Event Handlers

### CartItem Created

When a user adds a dynamic Pterodactyl product to their cart:
1. Check if product has a pricing config (is a dynamic product)
2. Extract resource selections from configurable options
3. Create a reservation with 15-minute TTL
4. Store reservation token in cart item properties

```php
<?php

private function handleCartItemCreated($event): void
{
    $cartItem = $event->cartItem;
    $properties = $cartItem->properties ?? [];
    
    // Check if this is a dynamic Pterodactyl product
    if (!$this->isDynamicPterodactylProduct($cartItem->product_id)) {
        return;
    }
    
    // Check if required properties exist (from configurable options)
    if (!isset($properties['memory'], $properties['cpu'], $properties['disk'], $properties['location'])) {
        \Log::debug('CartItem missing required properties for dynamic reservation', [
            'cart_item_id' => $cartItem->id,
            'properties' => array_keys($properties),
        ]);
        return;
    }
    
    try {
        $reservationService = app(ReservationService::class);
        
        $reservation = $reservationService->create(
            productId: $cartItem->product_id,
            locationId: (int) $properties['location'],
            resources: [
                'memory' => (int) $properties['memory'],
                'cpu' => (int) $properties['cpu'],
                'disk' => (int) $properties['disk'],
            ],
            cartItemId: $cartItem->id,
            userId: auth()->id()
        );
        
        // Store reservation token in cart item for later reference
        // Using underscore prefix to indicate internal properties
        $cartItem->update([
            'properties' => array_merge($properties, [
                '_reservation_token' => $reservation['token'],
                '_selected_node' => $reservation['node_id'],
                '_calculated_price' => $reservation['pricing']['total'],
            ]),
        ]);
        
        \Log::info('Created resource reservation for cart item', [
            'cart_item_id' => $cartItem->id,
            'reservation_token' => substr($reservation['token'], 0, 8) . '...',
            'node_id' => $reservation['node_id'],
            'expires_at' => $reservation['expires_at'],
        ]);
        
    } catch (\Exception $e) {
        // Log error but don't block the cart operation
        // User can still checkout, but without guaranteed resources
        \Log::error('Failed to create resource reservation', [
            'cart_item_id' => $cartItem->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

### CartItem Deleted

When a user removes an item from their cart:

```php
<?php

private function handleCartItemDeleted($event): void
{
    $cartItem = $event->cartItem;
    $properties = $cartItem->properties ?? [];
    
    // Check if this cart item had a reservation
    if (!isset($properties['_reservation_token'])) {
        return;
    }
    
    try {
        $reservationService = app(ReservationService::class);
        $reservationService->cancel($properties['_reservation_token']);
        
        \Log::info('Cancelled reservation for deleted cart item', [
            'cart_item_id' => $cartItem->id,
            'reservation_token' => substr($properties['_reservation_token'], 0, 8) . '...',
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Failed to cancel reservation', [
            'token' => substr($properties['_reservation_token'], 0, 8) . '...',
            'error' => $e->getMessage(),
        ]);
    }
}
```

### Invoice Updated (Payment)

When an invoice status changes to 'paid':
1. Find associated services
2. Verify resources are still available (final check)
3. Confirm the reservation
4. Link reservation to service

```php
<?php

private function handleInvoiceUpdated($event): void
{
    $invoice = $event->invoice;
    
    // Only process when invoice becomes paid
    if ($invoice->status !== 'paid') {
        return;
    }
    
    // Check if status actually changed to paid (not already paid)
    if (!$invoice->wasChanged('status')) {
        return;
    }
    
    foreach ($invoice->items as $item) {
        // Skip items without a service
        if (!$item->service_id) {
            continue;
        }
        
        $service = $item->service;
        if (!$service) {
            continue;
        }
        
        $properties = $service->properties ?? [];
        
        // Check if this service has a reservation
        if (!isset($properties['_reservation_token'])) {
            continue;
        }
        
        try {
            $reservationService = app(ReservationService::class);
            $resourceService = app(ResourceCalculationService::class);
            
            $reservation = $reservationService->getByToken($properties['_reservation_token']);
            
            if (!$reservation) {
                \Log::warning('Reservation not found for paid invoice', [
                    'service_id' => $service->id,
                    'invoice_id' => $invoice->id,
                ]);
                continue;
            }
            
            // CRITICAL: Final availability verification
            $available = $resourceService->verifyAvailability(
                $reservation->node_id,
                [
                    'memory' => $reservation->memory,
                    'cpu' => $reservation->cpu,
                    'disk' => $reservation->disk,
                ]
            );
            
            if (!$available) {
                // This should rarely happen, but we need to handle it
                \Log::error('Resources no longer available for paid service', [
                    'service_id' => $service->id,
                    'node_id' => $reservation->node_id,
                    'memory' => $reservation->memory,
                    'cpu' => $reservation->cpu,
                    'disk' => $reservation->disk,
                ]);
                
                // TODO: Notify admin for manual intervention
                // The server will still be created by Pterodactyl extension,
                // but may fail due to insufficient resources
                continue;
            }
            
            // Confirm the reservation
            $reservationService->confirm($properties['_reservation_token'], $service->id);
            
            \Log::info('Confirmed reservation for paid service', [
                'service_id' => $service->id,
                'node_id' => $reservation->node_id,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to confirm reservation', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### Service Created

Track when the Pterodactyl server is actually created:

```php
<?php

private function handleServiceCreated($event): void
{
    $service = $event->service;
    $properties = $service->properties ?? [];
    
    if (!isset($properties['_reservation_token'])) {
        return;
    }
    
    \Log::info('Service created with reservation', [
        'service_id' => $service->id,
        'reservation_token' => substr($properties['_reservation_token'], 0, 8) . '...',
        'product_id' => $service->product_id,
    ]);
    
    // The reservation should already be confirmed by handleInvoiceUpdated
    // This is just for logging/tracking purposes
}
```

---

## Helper Method

```php
<?php

/**
 * Check if a product has dynamic pricing configuration
 */
private function isDynamicPterodactylProduct(int $productId): bool
{
    return \DB::table('ptero_pricing_configs')
        ->where('product_id', $productId)
        ->where('is_active', true)
        ->exists();
}
```

---

## Reservation TTL Extension

When user reaches the checkout/payment page, extend their reservation to prevent expiry:

```php
<?php
// Could be triggered via JavaScript or a checkout page event

Event::listen('checkout.started', function ($event) {
    $cart = $event->cart;
    
    foreach ($cart->items as $item) {
        $properties = $item->properties ?? [];
        
        if (isset($properties['_reservation_token'])) {
            $reservationService = app(ReservationService::class);
            $reservationService->extend($properties['_reservation_token'], 15);
        }
    }
});
```

Alternatively, the frontend JavaScript can call the extend API endpoint when the user reaches checkout.

---

## Graceful Degradation

The event handlers are designed to fail gracefully:

| Scenario | Behavior |
|----------|----------|
| Pterodactyl API down | Log error, allow cart operation |
| No nodes available | Log error, allow cart operation |
| Reservation expired | Log warning, server creation may fail |
| Database error | Log error, allow cart operation |

**Philosophy**: Don't block the customer's purchase flow. If reservations fail, the worst case is the server creation might fail later, which can be handled manually.

---

## Event List Reference

Paymenter events used:

| Event | When Fired |
|-------|------------|
| `CartItem\Created` | Item added to cart |
| `CartItem\Updated` | Item quantity/properties changed |
| `CartItem\Deleted` | Item removed from cart |
| `Invoice\Created` | Invoice generated |
| `Invoice\Updated` | Invoice status changes |
| `Service\Created` | Service provisioned |

Full event list: https://paymenter.org/development/event-list

---

## Testing Events

To manually test event handlers:

```php
// In tinker or a test
$cartItem = \App\Models\CartItem::find(123);

// Manually fire the event
event(new \App\Events\CartItem\Created($cartItem));

// Check logs for handler execution
```
