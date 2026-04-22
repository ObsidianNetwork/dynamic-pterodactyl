<?php

/**
 * API Routes for DynamicPterodactyl
 *
 * @see 03-API.md for full endpoint specifications
 */

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\AvailabilityController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\PricingController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\ReservationController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\Admin\AdminCapacityController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\Admin\AdminReservationController;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Middleware\EnsureUserIsAdmin;

// Availability and pricing — throttled (30 req/min) to protect Pterodactyl API budget
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth', 'throttle:30,1'])->group(function () {
    Route::get('/availability/{locationId}', [AvailabilityController::class, 'getByLocation']);
    Route::get('/availability/{locationId}/nodes', [AvailabilityController::class, 'getNodes']);
    Route::post('/pricing/calculate', [PricingController::class, 'calculate']);
    Route::get('/pricing/config/{productId}', [PricingController::class, 'getConfig']);
});

// Reservation endpoints — no throttle; these are cart-lifecycle-driven, not polling
Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth'])->group(function () {
    Route::post('/reservation', [ReservationController::class, 'create']);
    Route::get('/reservation/{token}', [ReservationController::class, 'get']);
    Route::delete('/reservation/{token}', [ReservationController::class, 'cancel']);
    Route::post('/reservation/{token}/extend', [ReservationController::class, 'extend']);
});

// Admin routes — session-based, gated by non-null role (matches User::canAccessPanel)
Route::prefix('api/dynamic-pterodactyl/admin')
    ->middleware(['web', 'auth', EnsureUserIsAdmin::class, 'throttle:30,1'])
    ->group(function () {
        Route::get('/reservations', [AdminReservationController::class, 'index']);
        Route::post('/reservations/{token}/cancel', [AdminReservationController::class, 'cancel']);
        Route::get('/capacity', [AdminCapacityController::class, 'summary']);
    });
