<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $user = $request->user();

        $validated = validator([
            ...$request->all(),
            'idempotency_key' => $request->header('Idempotency-Key', $request->input('idempotency_key')),
        ], [
            'product_id' => 'required|integer|exists:products,id',
            'location_id' => 'required|integer',
            'memory' => 'required|integer|min:1',
            'cpu' => 'required|integer|min:1',
            'disk' => 'required|integer|min:1',
            'cart_item_id' => 'nullable|integer|exists:cart_items,id',
            'idempotency_key' => ['nullable', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
        ])->validate();

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
                userId: $user?->id,
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
        $user = Auth::user();
        $reservation = $this->reservationService->getByToken($token);

        if (! $reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }

        // Only allow owner or admin to view
        if ($reservation->user_id !== $user?->id && ! ($user?->is_admin ?? false)) {
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
        $user = Auth::user();
        $reservation = $this->reservationService->getByToken($token);

        if (! $reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }

        if ($reservation->user_id !== $user?->id && ! ($user?->is_admin ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $result = $this->reservationService->cancel($token, null, 'customer');

        return response()->json([
            'success' => $result,
            'message' => $result ? 'Reservation cancelled' : 'Failed to cancel reservation',
        ]);
    }

    public function extend(Request $request, string $token): JsonResponse
    {
        $user = Auth::user();
        $validated = $request->validate([
            'minutes' => 'integer|min:1|max:60',
        ]);

        $reservation = $this->reservationService->getByToken($token);

        if (! $reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }

        if ($reservation->user_id !== $user?->id && ! ($user?->is_admin ?? false)) {
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
