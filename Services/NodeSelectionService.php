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
     * Algorithm: memory/disk headroom weighting.
     *
     * Pterodactyl does not expose authoritative node CPU capacity, so CPU is
     * passed through as a server limit but is never treated as hard inventory.
     */
    public function selectBestNode(
        int $locationId,
        array $requirements,
        ?string $excludeReservationToken = null
    ): ?array
    {
        $locationData = $this->resourceService->getLocationAvailability(
            $locationId,
            $excludeReservationToken
        );

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
            if ($node['available']['disk'] < $requirements['disk']) {
                continue;
            }

            // Calculate remaining headroom after allocation
            $remainingMemory = $node['available']['memory'] - $requirements['memory'];
            $remainingDisk = $node['available']['disk'] - $requirements['disk'];

            $memoryScore = ($remainingMemory / max(1, $node['total']['memory'])) * 0.60;
            $diskScore = ($remainingDisk / max(1, $node['total']['disk'])) * 0.40;

            $score = $memoryScore + $diskScore;

            $candidates[] = [
                'node' => $node,
                'score' => $score,
                'remaining' => [
                    'memory' => $remainingMemory,
                    'cpu' => null,
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
