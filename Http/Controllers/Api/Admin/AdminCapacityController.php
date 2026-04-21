<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

class AdminCapacityController
{
    private ResourceCalculationService $resourceService;

    public function __construct(ResourceCalculationService $resourceService)
    {
        $this->resourceService = $resourceService;
    }

    public function summary(): JsonResponse
    {
        try {
            $locations = $this->resourceService->getLocations();
            $result = [];

            foreach ($locations as $location) {
                $availability = $this->resourceService->getLocationAvailability($location['id']);
                $result[] = [
                    'id'     => $location['id'],
                    'name'   => $location['long'],
                    'short'  => $location['short'],
                    'nodes'  => $availability['nodes'],
                    'totals' => [
                        'capacity'  => $availability['total_capacity'],
                        'allocated' => $availability['total_allocated'],
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'locations'    => $result,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch capacity',
                'error'   => $e->getMessage(),
            ], 503);
        }
    }
}
