<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

class NodeSelectionService
{
    private ResourceCalculationService $resourceService;

    public function __construct(ResourceCalculationService $resourceService)
    {
        $this->resourceService = $resourceService;
    }

    /**
     * Select the best node for given resource requirements
     *
     * Algorithm: Best-fit with headroom weighting
     * - Memory: 50% weight (most commonly upgraded)
     * - Disk: 35% weight (harder to migrate)
     * - CPU: 15% weight (often unlimited/shared)
     */
    public function selectBestNode(int $locationId, array $requirements): ?array
    {
        $locationData = $this->resourceService->getLocationAvailability($locationId);

        $candidates = [];

        foreach ($locationData['nodes'] as $node) {
            // Skip nodes in maintenance mode
            if ($node['maintenance_mode'] ?? false) {
                continue;
            }

            // Check if node can accommodate requirements
            if ($node['available']['memory'] < $requirements['memory']) {
                continue;
            }
            if ($node['available']['cpu'] < $requirements['cpu']) {
                continue;
            }
            if ($node['available']['disk'] < $requirements['disk']) {
                continue;
            }

            // Calculate remaining headroom after allocation
            $remainingMemory = $node['available']['memory'] - $requirements['memory'];
            $remainingCpu = $node['available']['cpu'] - $requirements['cpu'];
            $remainingDisk = $node['available']['disk'] - $requirements['disk'];

            // Weighted score: prioritize memory headroom, then disk, then CPU
            $memoryScore = ($remainingMemory / max(1, $node['total']['memory'])) * 0.50;
            $diskScore = ($remainingDisk / max(1, $node['total']['disk'])) * 0.35;
            $cpuScore = ($remainingCpu / max(1, $node['total']['cpu'])) * 0.15;

            $score = $memoryScore + $diskScore + $cpuScore;

            $candidates[] = [
                'node' => $node,
                'score' => $score,
                'remaining' => [
                    'memory' => $remainingMemory,
                    'cpu' => $remainingCpu,
                    'disk' => $remainingDisk,
                ],
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        // Sort by score descending, return highest
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $candidates[0]['node'];
    }

    /**
     * Get maximum allocatable resources across a location
     */
    public function getMaxAvailable(int $locationId): array
    {
        $locationData = $this->resourceService->getLocationAvailability($locationId);

        return $locationData['max_available'];
    }
}
