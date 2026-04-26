<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ConfigOptionSetupService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

class Dashboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Dynamic Pterodactyl';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'dynamic-pterodactyl';

    protected static ?string $title = 'Dynamic Pterodactyl';

    protected static ?string $navigationLabel = 'Dashboard';

    protected string $view = 'dynamic-pterodactyl::admin.dashboard';

    public function getViewData(): array
    {
        $resourceService = \app(ResourceCalculationService::class);
        $reservationService = \app(ReservationService::class);

        $connectionStatus = $resourceService->testConnection();
        $stats = $reservationService->getStatistics('30d');

        $snapshot = [];
        $locations = [];
        try {
            $snapshot = $resourceService->buildClusterSnapshot();

            if (isset($snapshot['error'])) {
                $connectionStatus = [
                    'success' => false,
                    'message' => $snapshot['error'],
                ];
            }

            foreach ($snapshot['locations'] as $location) {
                $availability = $this->buildLocationCapacity($snapshot, $location['id']);
                $locations[] = array_merge($location, [
                    'capacity' => $availability,
                    'health' => $this->calculateLocationHealth($availability),
                ]);
            }
        } catch (\Exception $e) {
            // Handle gracefully
        }

        // Count products with dynamic_slider config options (from native Paymenter system)
        $productsWithSliders = ConfigOptionSetupService::getProductsWithSlidersCount();

        $pendingReservations = DB::table('ptero_resource_reservations')
            ->where('status', 'pending')
            ->where('expires_at', '>', \now())
            ->count();

        return [
            'connectionStatus' => $connectionStatus,
            'stats' => $stats,
            'locations' => $locations,
            'productsWithSliders' => $productsWithSliders,
            'pendingReservations' => $pendingReservations,
        ];
    }

    private function calculateLocationHealth(array $availability): string
    {
        $memoryUtil = $availability['total_capacity']['memory'] > 0
            ? ($availability['total_allocated']['memory'] / $availability['total_capacity']['memory']) * 100
            : 100;

        if ($memoryUtil >= 95) {
            return 'critical';
        }
        if ($memoryUtil >= 80) {
            return 'warning';
        }

        return 'healthy';
    }

    private function buildLocationCapacity(array $snapshot, int $locationId): array
    {
        $locationSnapshot = $snapshot['by_location'][$locationId] ?? [
            'nodes' => [],
            'totals' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            'allocated' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
        ];

        $nodes = array_map(
            fn (int $nodeId) => $snapshot['nodes'][$nodeId]['node_availability'],
            $locationSnapshot['nodes']
        );

        return [
            'location_id' => $locationId,
            'nodes' => $nodes,
            'max_available' => [
                'memory' => \collect($nodes)->max('available.memory') ?? 0,
                'cpu' => \collect($nodes)->max('available.cpu') ?? 0,
                'disk' => \collect($nodes)->max('available.disk') ?? 0,
            ],
            'total_capacity' => $locationSnapshot['totals'],
            'total_allocated' => $locationSnapshot['allocated'],
        ];
    }
}
