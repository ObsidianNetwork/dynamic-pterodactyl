<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Pages;

use Filament\Pages\Page;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

class NodeMonitoring extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Dynamic Pterodactyl';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'dynamic-pterodactyl/nodes';

    protected static ?string $title = 'Node Monitoring';

    protected string $view = 'dynamic-pterodactyl::admin.node-monitoring';

    public function getViewData(): array
    {
        $resourceService = \app(ResourceCalculationService::class);
        $locations = [];
        $error = null;

        try {
            $snapshot = $resourceService->buildClusterSnapshot();
            $error = $snapshot['error'] ?? null;

            foreach ($snapshot['locations'] as $location) {
                $locationSnapshot = $snapshot['by_location'][$location['id']] ?? [
                    'nodes' => [],
                    'totals' => ['memory' => 0, 'cpu' => null, 'disk' => 0],
                    'allocated' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
                ];

                $locations[] = [
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
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        return [
            'locations' => $locations,
            'lastUpdated' => \now(),
            'error' => $error,
        ];
    }
}
