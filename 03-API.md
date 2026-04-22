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
    
    // Reservations
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

use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\AdminController;

Route::prefix('api/dynamic-pterodactyl/admin')
    ->middleware(['web', 'auth', EnsureUserIsAdmin::class, 'throttle:30,1'])
    ->group(function () {
        
        // Availability
        Route::get('/availability/{locationId}/nodes', [AvailabilityController::class, 'getNodes']);

        // Dashboard
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/statistics', [AdminController::class, 'statistics']);
        
        // Health checks
        Route::post('/test-connection', [AdminController::class, 'testConnection']);
        Route::post('/validate-config/{productId}', [AdminController::class, 'validateConfig']);
        
        // Reservations management
        Route::get('/reservations', [AdminController::class, 'listReservations']);
        Route::post('/reservations/{id}/cancel', [AdminController::class, 'cancelReservation']);
        Route::post('/reservations/{id}/extend', [AdminController::class, 'extendReservation']);
        Route::post('/reservations/cleanup', [AdminController::class, 'cleanupReservations']);
        
        // Audit logs
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
        
        // Import/Export
        Route::get('/export/pricing-configs', [AdminController::class, 'exportPricingConfigs']);
        Route::post('/import/pricing-configs', [AdminController::class, 'importPricingConfigs']);
    });
```

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

Calculate price for given resource configuration.

**Request:**
```json
{
    "product_id": 5,
    "memory": 8192,
    "cpu": 400,
    "disk": 102400
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "total": 24.00,
        "breakdown": [
            { "label": "Base Price", "amount": 5.00 },
            { "label": "Memory (8 GB)", "amount": 4.00 },
            { "label": "CPU (4 cores)", "amount": 8.00 },
            { "label": "Disk (100 GB)", "amount": 7.00 }
        ],
        "model": "linear"
    }
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

Create a resource reservation.

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
            "total": 24.00,
            "breakdown": [...],
            "model": "linear"
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
        "calculated_price": 24.00,
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
            
            return response()->json([
                'success' => true,
                'data' => [
                    'location_id' => $locationId,
                    'max_memory' => $maxAvailable['memory'],
                    'max_cpu' => $maxAvailable['cpu'],
                    'max_disk' => $maxAvailable['disk'],
                    'node_count' => count($locationData['nodes']),
                    'has_capacity' => $maxAvailable['memory'] > 0,
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

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PricingCalculatorService;

class PricingController
{
    private PricingCalculatorService $pricingService;
    
    public function __construct(PricingCalculatorService $pricingService)
    {
        $this->pricingService = $pricingService;
    }
    
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'memory' => 'required|integer|min:1',
            'cpu' => 'required|integer|min:1',
            'disk' => 'required|integer|min:1',
        ]);
        
        try {
            $pricing = $this->pricingService->calculate(
                $validated['product_id'],
                [
                    'memory' => $validated['memory'],
                    'cpu' => $validated['cpu'],
                    'disk' => $validated['disk'],
                ]
            );
            
            return response()->json([
                'success' => true,
                'data' => $pricing,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Price calculation failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function getConfig(int $productId): JsonResponse
    {
        $config = DB::table('config_options')
            ->where('product_id', $productId)
            ->where('type', 'dynamic_slider')
            ->first();
        
        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'No pricing config found for this product',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $config->product_id,
                'pricing_model' => $config->pricing_model,
                'sliders' => [
                    'memory' => [
                        'enabled' => (bool) $config->enable_memory_slider,
                        'min' => $config->min_memory,
                        'max' => $config->max_memory,
                        'step' => $config->memory_step,
                        'default' => $config->default_memory,
                    ],
                    'cpu' => [
                        'enabled' => (bool) $config->enable_cpu_slider,
                        'min' => $config->min_cpu,
                        'max' => $config->max_cpu,
                        'step' => $config->cpu_step,
                        'default' => $config->default_cpu,
                    ],
                    'disk' => [
                        'enabled' => (bool) $config->enable_disk_slider,
                        'min' => $config->min_disk,
                        'max' => $config->max_disk,
                        'step' => $config->disk_step,
                        'default' => $config->default_disk,
                    ],
                ],
                'display' => json_decode($config->display_config, true),
                'allowed_locations' => json_decode($config->allowed_locations, true),
            ],
        ]);
    }
}
```

### ReservationController

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class ReservationController
{
    private ReservationService $reservationService;
    
    public function __construct(ReservationService $reservationService)
    {
        $this->reservationService = $reservationService;
    }
    
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'location_id' => 'required|integer',
            'memory' => 'required|integer|min:1',
            'cpu' => 'required|integer|min:1',
            'disk' => 'required|integer|min:1',
            'cart_item_id' => 'nullable|integer|exists:cart_items,id',
        ]);
        
        try {
            $reservation = $this->reservationService->create(
                productId: $validated['product_id'],
                locationId: $validated['location_id'],
                resources: [
                    'memory' => $validated['memory'],
                    'cpu' => $validated['cpu'],
                    'disk' => $validated['disk'],
                ],
                cartItemId: $validated['cart_item_id'] ?? null,
                userId: auth()->id()
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
