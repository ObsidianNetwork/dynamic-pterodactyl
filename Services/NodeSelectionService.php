<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

class NodeSelectionService
{
    private ResourceCalculationService $resourceService;

    public function __construct(
        ResourceCalculationService $resourceService,
        private readonly AllocationSelectionService $allocations
    ) {
        $this->resourceService = $resourceService;
    }

    /**
     * Select an eligible node and one primary Pterodactyl allocation.
     *
     * @param  array{memory: int, cpu: int, disk: int}  $requirements
     */
    public function selectBestNode(
        int $locationId,
        array $requirements,
        ?string $excludeReservationToken = null
    ): ?array {
        return $this->selectBestNodeWithAllocations(
            $locationId,
            $requirements,
            1,
            $excludeReservationToken
        );
    }

    /**
     * Select an eligible node and the exact free allocations that should be
     * locked by the reservation transaction.
     *
     * @param  array{memory: int, cpu: int, disk: int}  $requirements
     */
    public function selectBestNodeWithAllocations(
        int $locationId,
        array $requirements,
        int $allocationCount = 1,
        ?string $excludeReservationToken = null,
        array $requiredPorts = [],
        array $allowedPortRanges = [],
        bool $dedicatedIp = false
    ): ?array {
        $locationData = $this->resourceService->getLocationAvailability(
            $locationId,
            $excludeReservationToken
        );

        $candidates = [];

        foreach ($locationData['nodes'] as $node) {
            if (! ($node['eligible'] ?? false)) {
                continue;
            }

            $selectedAllocations = $this->allocations->select(
                $node['available_allocations'] ?? [],
                $allocationCount,
                $requiredPorts,
                $allowedPortRanges,
                $dedicatedIp
            );
            if ($selectedAllocations === null) {
                continue;
            }

            foreach (['memory', 'cpu', 'disk'] as $resource) {
                if (
                    ! isset($requirements[$resource])
                    || ! is_int($requirements[$resource])
                    || $requirements[$resource] < 1
                    || (int) ($node['available'][$resource] ?? -1) < $requirements[$resource]
                ) {
                    continue 2;
                }
            }

            $remaining = [
                'memory' => $node['available']['memory'] - $requirements['memory'],
                'cpu' => $node['available']['cpu'] - $requirements['cpu'],
                'disk' => $node['available']['disk'] - $requirements['disk'],
            ];

            $score = ($remaining['memory'] / max(1, $node['total']['memory'])) * 0.50
                + ($remaining['cpu'] / max(1, $node['total']['cpu'])) * 0.15
                + ($remaining['disk'] / max(1, $node['total']['disk'])) * 0.35;

            $node['selected_allocations'] = $selectedAllocations;
            $candidates[] = [
                'node' => $node,
                'score' => $score,
                'remaining' => $remaining,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];

            return $score !== 0
                ? $score
                : $left['node']['node_id'] <=> $right['node']['node_id'];
        });

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
