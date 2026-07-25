<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

class AvailabilityController
{
    private ResourceCalculationService $resourceService;

    public function __construct(
        ResourceCalculationService $resourceService
    ) {
        $this->resourceService = $resourceService;
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
