<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class AdminReservationController
{
    private ReservationService $reservationService;

    public function __construct(ReservationService $reservationService)
    {
        $this->reservationService = $reservationService;
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'      => 'nullable|string|in:pending,confirmed,cancelled,expired',
            'location_id' => 'nullable|integer',
            'node_id'     => 'nullable|integer',
            'user_id'     => 'nullable|integer',
            'per_page'    => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->reservationService->queryAll($validated);

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($validated['per_page'] ?? 25),
        ]);
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $reservation = $this->reservationService->getByToken($token);

        if ($reservation === null) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation not found',
            ], 404);
        }

        if ($reservation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => "Only pending reservations can be cancelled (current status: {$reservation->status})",
            ], 409);
        }

        $actor = $request->user();
        abort_if($actor === null, 401);

        $result = $this->reservationService->cancel($token, $validated['reason'], 'admin', $actor);

        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Reservation cancelled',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Reservation could not be cancelled because its status changed',
        ], 409);
    }
}
