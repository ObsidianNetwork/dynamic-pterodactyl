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
        $resourceService = app(ResourceCalculationService::class);
        $locations = [];
        $error = null;

        try {
            foreach ($resourceService->getLocations() as $location) {
                $availability = $resourceService->getLocationAvailability($location['id']);
                $locations[] = [
                    'id' => $location['id'],
                    'name' => $location['long'],
                    'short' => $location['short'],
                    'nodes' => $availability['nodes'],
                    'totals' => [
                        'capacity' => $availability['total_capacity'],
                        'allocated' => $availability['total_allocated'],
                    ],
                ];
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        return [
            'locations' => $locations,
            'lastUpdated' => now(),
            'error' => $error,
        ];
    }
}
