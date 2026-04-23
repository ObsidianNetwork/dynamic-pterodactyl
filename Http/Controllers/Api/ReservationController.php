<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Requests\StoreReservationRequest;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class ReservationController extends Controller
{
    use AuthorizesRequests;

    private ReservationService $reservationService;

    public function __construct(ReservationService $reservationService)
    {
        $this->reservationService = $reservationService;
    }

    public function create(StoreReservationRequest $request): JsonResponse
    {
        $user = $request->user();
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
        $reservation = ResourceReservation::query()->where('token', $token)->first();

        if (! $reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }

        $this->authorize('view', $reservation);

        return response()->json([
            'success' => true,
            'data' => $reservation,
        ]);
    }

    public function cancel(string $token): JsonResponse
    {
        $reservation = ResourceReservation::query()->where('token', $token)->first();

        if (! $reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }

        $this->authorize('cancel', $reservation);

        $result = $this->reservationService->cancel($token, null, 'customer');

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

        $reservation = ResourceReservation::query()->where('token', $token)->first();

        if (! $reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }

        $this->authorize('extend', $reservation);

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
