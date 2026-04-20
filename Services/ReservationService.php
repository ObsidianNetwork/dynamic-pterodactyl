<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\Extension;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationService
{
    private NodeSelectionService $nodeService;
    private PricingCalculatorService $pricingService;
    private AuditLogService $auditService;
    private int $ttlMinutes;

    public function __construct(
        NodeSelectionService $nodeService,
        PricingCalculatorService $pricingService,
        AuditLogService $auditService
    ) {
        $this->nodeService = $nodeService;
        $this->pricingService = $pricingService;
        $this->auditService = $auditService;

        $config = Extension::where('extension', 'DynamicPterodactyl')
            ->first()
            ?->settings
            ->pluck('value', 'key')
            ->toArray() ?? [];
        $this->ttlMinutes = (int) ($config['reservation_ttl'] ?? 15);
    }

    /**
     * Create a resource reservation
     *
     * Uses database transaction with pessimistic locking
     * Retries up to 5 times on deadlock
     */
    public function create(
        int $productId,
        int $locationId,
        array $resources,
        ?int $cartItemId = null,
        ?int $userId = null
    ): array {
        return DB::transaction(function () use ($productId, $locationId, $resources, $cartItemId, $userId) {
            // Lock pending reservations for this location
            DB::table('ptero_resource_reservations')
                ->where('location_id', $locationId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            // Find best node
            $node = $this->nodeService->selectBestNode($locationId, $resources);

            if (! $node) {
                throw new \RuntimeException('No node with sufficient resources available');
            }

            // Calculate pricing
            $pricing = $this->pricingService->calculate($productId, $resources);

            // Create reservation
            $token = Str::random(64);
            $expiresAt = now()->addMinutes($this->ttlMinutes);

            $id = DB::table('ptero_resource_reservations')->insertGetId([
                'token' => $token,
                'cart_item_id' => $cartItemId,
                'user_id' => $userId,
                'node_id' => $node['node_id'],
                'location_id' => $locationId,
                'memory' => $resources['memory'],
                'cpu' => $resources['cpu'],
                'disk' => $resources['disk'],
                'calculated_price' => $pricing['total'],
                'pricing_breakdown' => json_encode($pricing['breakdown']),
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $id,
                'token' => $token,
                'node_id' => $node['node_id'],
                'node_name' => $node['name'],
                'expires_at' => $expiresAt->toIso8601String(),
                'ttl_minutes' => $this->ttlMinutes,
                'pricing' => $pricing,
            ];
        }, 5); // 5 retry attempts for deadlock
    }

    /**
     * Confirm a reservation (after successful payment)
     */
    public function confirm(string $token, int $serviceId): bool
    {
        return DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->update([
                'status' => 'confirmed',
                'service_id' => $serviceId,
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * Cancel a reservation
     */
    public function cancel(string $token, ?string $reason = null, bool $isAdminAction = false): bool
    {
        $reservation = $this->getByToken($token);

        if (! $reservation) {
            return false;
        }

        $result = DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'admin_notes' => $reason,
                'updated_at' => now(),
            ]) > 0;

        if ($result && $isAdminAction) {
            $this->auditService->log('cancelled', 'reservation', $reservation->id, [
                'reason' => $reason,
                'resources' => [
                    'memory' => $reservation->memory,
                    'cpu' => $reservation->cpu,
                    'disk' => $reservation->disk,
                ],
            ]);
        }

        return $result;
    }

    /**
     * Extend reservation TTL
     */
    public function extend(string $token, int $additionalMinutes = 15): bool
    {
        return DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->update([
                'expires_at' => DB::raw("DATE_ADD(expires_at, INTERVAL {$additionalMinutes} MINUTE)"),
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * Get reservation by token
     */
    public function getByToken(string $token): ?object
    {
        return DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->first();
    }

    /**
     * Get reservation by cart item
     */
    public function getByCartItem(int $cartItemId): ?object
    {
        return DB::table('ptero_resource_reservations')
            ->where('cart_item_id', $cartItemId)
            ->where('status', 'pending')
            ->first();
    }

    /**
     * Get all reservations with filters (for admin)
     */
    public function getAll(array $filters = []): Collection
    {
        $query = DB::table('ptero_resource_reservations')
            ->leftJoin('users', 'ptero_resource_reservations.user_id', '=', 'users.id')
            ->select([
                'ptero_resource_reservations.*',
                'users.name as user_name',
                'users.email as user_email',
            ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }
        if (! empty($filters['node_id'])) {
            $query->where('node_id', $filters['node_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('ptero_resource_reservations.user_id', $filters['user_id']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get reservation statistics
     */
    public function getStatistics(string $period = '30d'): array
    {
        $startDate = match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };

        $stats = DB::table('ptero_resource_reservations')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $revenue = DB::table('ptero_resource_reservations')
            ->where('created_at', '>=', $startDate)
            ->where('status', 'confirmed')
            ->sum('calculated_price');

        $avgResources = DB::table('ptero_resource_reservations')
            ->where('created_at', '>=', $startDate)
            ->where('status', 'confirmed')
            ->selectRaw('AVG(memory) as avg_memory, AVG(cpu) as avg_cpu, AVG(disk) as avg_disk')
            ->first();

        $total = array_sum($stats);
        $confirmed = $stats['confirmed'] ?? 0;
        $expired = $stats['expired'] ?? 0;
        $cancelled = $stats['cancelled'] ?? 0;

        return [
            'period' => $period,
            'total' => $total,
            'by_status' => $stats,
            'confirmed_revenue' => $revenue,
            'conversion_rate' => ($confirmed + $expired + $cancelled) > 0
                ? round($confirmed / ($confirmed + $expired + $cancelled) * 100, 1)
                : 0,
            'average_resources' => [
                'memory' => round($avgResources->avg_memory ?? 0),
                'cpu' => round($avgResources->avg_cpu ?? 0),
                'disk' => round($avgResources->avg_disk ?? 0),
            ],
        ];
    }

    /**
     * Cleanup expired reservations (called by scheduled job)
     */
    public function cleanupExpired(): int
    {
        return DB::table('ptero_resource_reservations')
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }
}
