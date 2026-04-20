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
        $resourceService = app(ResourceCalculationService::class);
        $reservationService = app(ReservationService::class);

        $connectionStatus = $resourceService->testConnection();
        $stats = $reservationService->getStatistics('30d');

        $locations = [];
        try {
            foreach ($resourceService->getLocations() as $location) {
                $availability = $resourceService->getLocationAvailability($location['id']);
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
            ->where('expires_at', '>', now())
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
}
