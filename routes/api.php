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

Route::prefix('api/dynamic-pterodactyl')->middleware(['web', 'auth'])->group(function () {

    // Availability endpoints
    Route::get('/availability/{locationId}', [AvailabilityController::class, 'getByLocation']);
    Route::get('/availability/{locationId}/nodes', [AvailabilityController::class, 'getNodes']);

    // Pricing endpoints
    Route::post('/pricing/calculate', [PricingController::class, 'calculate']);
    Route::get('/pricing/config/{productId}', [PricingController::class, 'getConfig']);

    // Reservation endpoints
    Route::post('/reservation', [ReservationController::class, 'create']);
    Route::get('/reservation/{token}', [ReservationController::class, 'get']);
    Route::delete('/reservation/{token}', [ReservationController::class, 'cancel']);
    Route::post('/reservation/{token}/extend', [ReservationController::class, 'extend']);
});

// Admin routes (to be implemented in Phase 3)
Route::prefix('api/dynamic-pterodactyl/admin')
    ->middleware(['web', 'auth', 'admin'])
    ->group(function () {
        // TODO: Admin API endpoints
    });
