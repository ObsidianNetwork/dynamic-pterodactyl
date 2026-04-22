<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

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
