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
            $snapshot = $this->resourceService->buildClusterSnapshot();
            $result = [];

            foreach ($snapshot['locations'] as $location) {
                $locationSnapshot = $snapshot['by_location'][$location['id']] ?? [
                    'nodes' => [],
                    'totals' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
                    'allocated' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
                ];

                $result[] = [
                    'id' => $location['id'],
                    'name' => $location['long'],
                    'short' => $location['short'],
                    'nodes' => array_map(
                        fn (int $nodeId) => $snapshot['nodes'][$nodeId]['node_availability'],
                        $locationSnapshot['nodes']
                    ),
                    'totals' => [
                        'capacity' => $locationSnapshot['totals'],
                        'allocated' => $locationSnapshot['allocated'],
                    ],
                ];
            }

            return \response()->json([
                'success' => true,
                'data' => [
                    'locations' => $result,
                    'generated_at' => $snapshot['generated_at'],
                    'error' => $snapshot['error'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            \report($e);

            return \response()->json([
                'success' => false,
                'message' => 'Failed to fetch capacity',
            ], 503);
        }
    }
}
