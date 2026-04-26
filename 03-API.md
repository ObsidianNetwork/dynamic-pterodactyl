# API Endpoints

> **Related docs**: [02-SERVICES.md](02-SERVICES.md) (services called by controllers), [06-FRONTEND.md](06-FRONTEND.md) (JavaScript that calls these APIs)

---

## Route Definitions

### Public API Routes (Authenticated Users)

```php
<?php
// routes/api.php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\AvailabilityController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\PricingController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\ReservationController;

Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth', 'throttle:30,1'])->group(function () {
    // Availability
    Route::get('/availability/{locationId}', [AvailabilityController::class, 'getByLocation']);

    // Pricing
    Route::post('/pricing/calculate', [PricingController::class, 'calculate']);
    Route::get('/pricing/config/{productId}', [PricingController::class, 'getConfig']);
});

// Reservation endpoints — throttled (10 req/min) for checkout-retry burst tolerance
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth', 'throttle:10,1'])->group(function () {
    Route::post('/reservation', [ReservationController::class, 'create']);
    Route::get('/reservation/{token}', [ReservationController::class, 'get']);
    Route::delete('/reservation/{token}', [ReservationController::class, 'cancel']);
    Route::post('/reservation/{token}/extend', [ReservationController::class, 'extend']);
});
```

> Pricing config no longer uses `ptero_pricing_configs`. It now uses native Paymenter ConfigOption rows with `type='dynamic_slider'` and `metadata.resource_type`.

### Admin API Routes

```php
<?php
// routes/api.php (continued)

use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\Admin\AdminCapacityController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\Admin\AdminReservationController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Middleware\EnsureUserIsAdmin;

Route::prefix('api/dynamic-pterodactyl/admin')
    ->middleware(['web', 'auth', EnsureUserIsAdmin::class, 'throttle:30,1'])
    ->group(function () {
        Route::get('/reservations', [AdminReservationController::class, 'index']);
        Route::post('/reservations/{token}/cancel', [AdminReservationController::class, 'cancel']);
        Route::get('/capacity', [AdminCapacityController::class, 'summary']);
        Route::get('/availability/{locationId}/nodes', [AvailabilityController::class, 'getNodes']);
    });
```

### Removed Endpoints

The following endpoints were previously documented but either never shipped or were retired by dp-09/dp-11:
- `POST /api/dynamic-pterodactyl/pricing/validate-config`
- `POST /api/dynamic-pterodactyl/admin/pricing/import`
- `GET /api/dynamic-pterodactyl/admin/pricing/export`
- `POST /api/dynamic-pterodactyl/admin/reservations/{token}/extend`
- `POST /api/dynamic-pterodactyl/admin/reservations/cleanup`
- `POST /api/dynamic-pterodactyl/admin/test-connection`
- `GET /api/dynamic-pterodactyl/admin/statistics`
- `GET /api/dynamic-pterodactyl/admin/dashboard`

Future readers who find references in old commits can ignore them.

---

## Endpoint Reference

### GET /api/dynamic-pterodactyl/availability/{locationId}

Get maximum available resources for a location.

**Response:**
```json
{
    "success": true,
    "data": {
        "location_id": 1,
        "max_memory": 32768,
        "max_cpu": 800,
        "max_disk": 204800,
        "node_count": 3,
        "has_capacity": true,
        "resource_capacity": {
            "memory": true,
            "cpu": true,
            "disk": true
        }
    }
}
```

`has_capacity` is conservative: it is only `true` when memory, CPU, and disk are all positive. `resource_capacity` exposes the per-resource booleans the frontend can use to explain which resource is exhausted.

### POST /api/dynamic-pterodactyl/pricing/calculate

Calculate price for a resource configuration by delegating to Paymenter core pricing primitives.

**Request:**
```json
{
    "product_id": 5,
    "plan_id": 12,
    "memory": 8192,
    "cpu": 400,
    "disk": 102400
}
```

`plan_id` is optional; when omitted, the first plan (sorted by `sort`) for the product is used. Only the slider resource types configured on the product are required — requests missing a configured slider return `422`. Requests with a `plan_id` belonging to a different product also return `422`. Products with no `dynamic_slider` config options return `404`.

`PricingController::calculate()` resolves the selected plan, adds `Plan::dynamicSliderBasePrice()` once when at least one slider is in scope, and sums each slider's `ConfigOption::calculateDynamicPriceDelta(...)` result into the response payload.

**Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "total": 24.00,
        "breakdown": [
            { "resource_type": "memory", "label": "Memory", "value": 8192, "display_value": "8 GB", "price": 4.00, "pricing_model": "linear" },
            { "resource_type": "cpu", "label": "CPU", "value": 400, "display_value": "4 cores", "price": 8.00, "pricing_model": "linear" },
            { "resource_type": "disk", "label": "Disk", "value": 102400, "display_value": "100 GB", "price": 7.00, "pricing_model": "linear" }
        ],
        "model": "linear"
    }
}
```

The shared base price is included in `total` but not duplicated in each breakdown row.

**Response (422 — foreign `plan_id`):**
```json
{
    "success": false,
    "message": "Selected plan does not belong to this product"
}
```

### GET /api/dynamic-pterodactyl/pricing/config/{productId}

Get pricing configuration and slider limits for a product.

**Response:**
```json
{
    "success": true,
    "data": {
        "product_id": 5,
        "pricing_model": "linear",
        "sliders": {
            "memory": {
                "enabled": true,
                "min": 1024,
                "max": 65536,
                "step": 1024,
                "default": 4096
            },
            "cpu": {
                "enabled": true,
                "min": 100,
                "max": 800,
                "step": 100,
                "default": 200
            },
            "disk": {
                "enabled": true,
                "min": 10240,
                "max": 512000,
                "step": 10240,
                "default": 51200
            }
        },
        "display": {
            "memory_label": "RAM",
            "cpu_label": "CPU Cores",
            "disk_label": "Storage",
            "show_breakdown": true
        },
        "allowed_locations": null
    }
}
```

### POST /api/dynamic-pterodactyl/reservation

Create a resource reservation. **Throttled at 10 req/min per authenticated user.**

**Headers:**
- `Idempotency-Key` *(optional, preferred)* — 8-64 characters, alphanumeric plus hyphen. When reused by the same user while an existing reservation is still `pending` or `confirmed`, the API returns the original reservation instead of creating a duplicate hold.

**Request:**
```json
{
    "product_id": 5,
    "location_id": 1,
    "memory": 8192,
    "cpu": 400,
    "disk": 102400,
    "cart_item_id": 123,
    "idempotency_key": "checkout-req-123"
}
```

**Validation rules:**
- Product must have `dynamic_slider` config options for dynamic reservations, otherwise the request is rejected with `422` and `This product is not configured for dynamic reservations`.
- Each configured resource must be present in the payload.
- Extra resource fields not configured on the product are rejected.
- Each selected resource must stay within the slider's `min`/`max` bounds and match its configured `step` increment.
- `Idempotency-Key` header or `idempotency_key` body field must match `^[A-Za-z0-9-]{8,64}$` when supplied.

**Response:**
```json
{
    "success": true,
    "data": {
        "id": 456,
        "token": "abc123def456...",
        "node_id": 1,
        "node_name": "Node-US-01",
        "expires_at": "2025-11-28T12:30:00Z",
        "ttl_minutes": 15,
        "pricing": {
            "total": 0,
            "breakdown": [],
            "model": "stored"
        }
    }
}
```

### GET /api/dynamic-pterodactyl/reservation/{token}

Get reservation details.

**Response:**
```json
{
    "success": true,
    "data": {
        "id": 456,
        "token": "abc123def456...",
        "status": "pending",
        "node_id": 1,
        "location_id": 1,
        "memory": 8192,
        "cpu": 400,
        "disk": 102400,
        "calculated_price": 0,
        "expires_at": "2025-11-28T12:30:00Z",
        "created_at": "2025-11-28T12:15:00Z"
    }
}
```

### DELETE /api/dynamic-pterodactyl/reservation/{token}

Cancel a reservation.

**Response:**
```json
{
    "success": true,
    "message": "Reservation cancelled"
}
```

### POST /api/dynamic-pterodactyl/reservation/{token}/extend

Extend reservation TTL.

**Request:**
```json
{
    "minutes": 15
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "expires_at": "2025-11-28T12:45:00Z"
    }
}
```

### GET /api/dynamic-pterodactyl/admin/availability/{locationId}/nodes

Get detailed per-node availability for admin diagnostics.

**Response:**
```json
{
    "success": true,
    "data": {
        "location_id": 1,
        "nodes": [
            {
                "node_id": 1,
                "name": "Node-US-01",
                "total": { "memory": 65536, "cpu": 1600, "disk": 512000 },
                "allocated": { "memory": 32768, "cpu": 800, "disk": 256000 },
                "reserved": { "memory": 4096, "cpu": 200, "disk": 20480 },
                "available": { "memory": 28672, "cpu": 600, "disk": 235520 },
                "utilization": { "memory": 56.2, "disk": 54.0 },
                "server_count": 12
            }
        ],
        "total_capacity": { "memory": 131072, "cpu": 3200, "disk": 1024000 },
        "total_allocated": { "memory": 65536, "cpu": 1600, "disk": 512000 }
    }
}
```

---

## Controllers

### AvailabilityController

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;

class AvailabilityController
{
    private ResourceCalculationService $resourceService;
    private NodeSelectionService $nodeService;
    
    public function __construct(
        ResourceCalculationService $resourceService,
        NodeSelectionService $nodeService
    ) {
        $this->resourceService = $resourceService;
        $this->nodeService = $nodeService;
    }
    
    public function getByLocation(int $locationId): JsonResponse
    {
        try {
            $maxAvailable = $this->nodeService->getMaxAvailable($locationId);
            $locationData = $this->resourceService->getLocationAvailability($locationId);
            $resourceCapacity = [
                'memory' => $maxAvailable['memory'] > 0,
                'cpu' => $maxAvailable['cpu'] > 0,
                'disk' => $maxAvailable['disk'] > 0,
            ];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'location_id' => $locationId,
                    'max_memory' => $maxAvailable['memory'],
                    'max_cpu' => $maxAvailable['cpu'],
                    'max_disk' => $maxAvailable['disk'],
                    'node_count' => count($locationData['nodes']),
                    'has_capacity' => $resourceCapacity['memory'] && $resourceCapacity['cpu'] && $resourceCapacity['disk'],
                    'resource_capacity' => $resourceCapacity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch availability',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function getNodes(int $locationId): JsonResponse
    {
        try {
            $locationData = $this->resourceService->getLocationAvailability($locationId);
            
            return response()->json([
                'success' => true,
                'data' => $locationData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch node details',
            ], 500);
        }
    }
}
```

### PricingController

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\SliderConfigReaderService;

class PricingController
{
    private SliderConfigReaderService $sliderConfigReader;

    public function __construct(SliderConfigReaderService $sliderConfigReader)
    {
        $this->sliderConfigReader = $sliderConfigReader;
    }

    public function calculate(Request $request): JsonResponse
    {
        // Phase 1: validate product_id (static)
        $request->validate(['product_id' => 'required|integer|exists:products,id']);
        $product = Product::query()->with(['configOptions', 'plans'])->findOrFail($request->integer('product_id'));

        $sliderOptions = $product->configOptions->where('type', 'dynamic_slider')->whereNull('parent_id');

        if ($sliderOptions->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'This product is not configured for dynamic pricing'], 404);
        }

        // Phase 2: build dynamic validation rules from configured sliders
        $rules = ['plan_id' => 'nullable|integer|exists:plans,id'];
        foreach ($sliderOptions as $option) {
            $rules[$option->getMetadata('resource_type', strtolower($option->name))] = 'required|integer|min:1';
        }
        $validated = array_merge(['product_id' => $product->id], $request->validate($rules));

        try {
            try {
                $plan = $this->resolvePlan($product, $validated['plan_id'] ?? null);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            $breakdown = [];
            $total = 0.0;
            $hasSlider = false;

            foreach ($sliderOptions as $option) {
                $resourceType = $option->getMetadata('resource_type', strtolower($option->name));
                $value = (float) ($validated[$resourceType] ?? 0);
                if ($value <= 0) continue;

                $hasSlider = true;
                $price = $option->calculateDynamicPriceDelta($value, $plan->billing_period, $plan->billing_unit);
                $breakdown[] = [
                    'resource_type' => $resourceType,
                    'label'         => $option->name,
                    'value'         => $value,
                    'display_value' => $option->formatValueForDisplay($value),
                    'price'         => round($price, 2),
                    'pricing_model' => $option->getMetadata('pricing.model', 'linear'),
                ];
                $total += $price;
            }

            if ($hasSlider) {
                $total += $plan->dynamicSliderBasePrice();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total'     => round($total, 2),
                    'breakdown' => $breakdown,
                    'model'     => $sliderOptions->first()?->getMetadata('pricing.model', 'linear') ?? 'linear',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DynamicPterodactyl price calculation failed', ['error' => $e->getMessage()]);
            $payload = ['success' => false, 'message' => 'Price calculation failed'];
            if (config('app.debug')) $payload['error'] = $e->getMessage();
            return response()->json($payload, 500);
        }
    }

    public function getConfig(int $productId): JsonResponse
    {
        $config = $this->sliderConfigReader->getConfig($productId);

        if (! $config['has_config']) {
            return response()->json(['success' => false, 'message' => 'No dynamic slider config options found for this product'], 404);
        }

        return response()->json(['success' => true, 'data' => ['product_id' => $productId, 'sliders' => $config['sliders']]]);
    }

    public function validate(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'errors' => ['Pricing validation endpoint has been retired']], 410);
    }

    private function resolvePlan(Product $product, ?int $planId): Plan { /* ... */ }
}
```

### ReservationController

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Requests\StoreReservationRequest;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class ReservationController
{
    private ReservationService $reservationService;
    
    public function __construct(ReservationService $reservationService)
    {
        $this->reservationService = $reservationService;
    }
    
    public function create(StoreReservationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $resources = [
            'memory' => (int) ($validated['memory'] ?? 0),
            'cpu' => (int) ($validated['cpu'] ?? 0),
            'disk' => (int) ($validated['disk'] ?? 0),
        ];
        
        try {
            $reservation = $this->reservationService->create(
                productId: $validated['product_id'],
                locationId: $validated['location_id'],
                resources: $resources,
                cartItemId: $validated['cart_item_id'] ?? null,
                userId: $request->user()?->id,
                idempotencyKey: $validated['idempotency_key'] ?? null,
            );
            
            return response()->json([
                'success' => true,
                'data' => $reservation,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create reservation',
            ], 500);
        }
    }
    
    public function get(string $token): JsonResponse
    {
        $reservation = $this->reservationService->getByToken($token);
        
        if (!$reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }
        
        // Only allow owner or admin to view
        if ($reservation->user_id !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }
        
        return response()->json([
            'success' => true,
            'data' => $reservation,
        ]);
    }
    
    public function cancel(string $token): JsonResponse
    {
        $reservation = $this->reservationService->getByToken($token);
        
        if (!$reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }
        
        if ($reservation->user_id !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }
        
        $result = $this->reservationService->cancel($token);
        
        return response()->json([
            'success' => $result,
            'message' => $result ? 'Reservation cancelled' : 'Failed to cancel reservation',
        ]);
    }
    
    public function extend(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'minutes' => 'integer|min:1|max:60',
        ]);
        
        $reservation = $this->reservationService->getByToken($token);
        
        if (!$reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }
        
        if ($reservation->user_id !== auth()->id() && !auth()->user()->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }
        
        $result = $this->reservationService->extend($token, $validated['minutes'] ?? 15);
        
        if ($result) {
            $updated = $this->reservationService->getByToken($token);
            return response()->json([
                'success' => true,
                'data' => [
                    'expires_at' => $updated->expires_at,
                ],
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to extend reservation',
        ], 500);
    }
}
```

---

## Error Response Format

All error responses follow this structure:

```json
{
    "success": false,
    "message": "Human-readable error message",
    "error": "Technical details (only in non-production)",
    "errors": {
        "field_name": ["Validation error message"]
    }
}
```

### HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 400 | Bad request (invalid parameters) |
| 401 | Unauthorized (not logged in) |
| 403 | Forbidden (not allowed) |
| 404 | Not found |
| 422 | Unprocessable (validation failed or business rule violation) |
| 500 | Server error |

---

## Rate Limiting

Consider implementing rate limiting for availability endpoints:

```php
// In RouteServiceProvider or middleware
RateLimiter::for('dynamic-pterodactyl', function ($request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```

Pterodactyl API limit: **240 requests/minute** — our endpoints should stay well under this.
