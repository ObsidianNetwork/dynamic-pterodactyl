<?php

/**
 * API Routes for DynamicPterodactyl
 *
 * @see 03-API.md for full endpoint specifications
 */

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\AvailabilityController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\ResourceQuoteController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\UpgradeQuoteController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\Admin\AdminCapacityController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\Admin\AdminReservationController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Middleware\EnsureUserIsAdmin;

// Customer stock quote — guest-safe, CSRF-protected, and intentionally does
// not expose node identity or other infrastructure detail.
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'throttle:30,1'])->group(function () {
    Route::post('/products/{product}/resource-quote', ResourceQuoteController::class)
        ->whereNumber('product');
});

// Existing-service stock quotes are authenticated and customer-owned.
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth', 'throttle:30,1'])->group(function () {
    Route::post('/services/{service}/upgrade-quote', UpgradeQuoteController::class);
});

// Admin routes — session-based, gated by non-null role (matches User::canAccessPanel)
Route::prefix('api/dynamic-pterodactyl/admin')
    ->middleware(['web', 'auth', EnsureUserIsAdmin::class, 'throttle:30,1'])
    ->group(function () {
        Route::get('/reservations', [AdminReservationController::class, 'index']);
        Route::post('/reservations/{token}/cancel', [AdminReservationController::class, 'cancel']);
        Route::get('/capacity', [AdminCapacityController::class, 'summary']);
        Route::get('/availability/{locationId}/nodes', [AvailabilityController::class, 'getNodes']);
    });
