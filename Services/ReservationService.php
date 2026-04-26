<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\Extension;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\Concerns\AuditsExtensionActions;

class ReservationService
{
    use AuditsExtensionActions;

    private NodeSelectionService $nodeService;

    private int $ttlMinutes;

    public function __construct(
        NodeSelectionService $nodeService
    ) {
        $this->nodeService = $nodeService;

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
        ?int $userId = null,
        ?string $idempotencyKey = null
    ): array {
        try {
            return DB::transaction(function () use ($productId, $locationId, $resources, $cartItemId, $userId, $idempotencyKey) {
                // Lock pending reservations for this location
                DB::table('ptero_resource_reservations')
                    ->where('location_id', $locationId)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->get();

                if ($idempotencyKey !== null) {
                    $this->expireStaleIdempotencyReservations($userId, $idempotencyKey);

                    $existingReservation = $this->getActiveByIdempotencyKey($userId, $idempotencyKey);
                    if ($existingReservation) {
                        Log::info('Returning existing reservation for idempotent create request', [
                            'reservation_id' => $existingReservation->id,
                            'user_id' => $userId,
                            'idempotency_key' => $idempotencyKey,
                        ]);

                        return $this->presentReservation($existingReservation);
                    }
                }

                $node = $this->nodeService->selectBestNode($locationId, $resources);

                if (!$node) {
                    throw new \RuntimeException('No node with sufficient resources available');
                }

                $token = Str::random(64);
                $expiresAt = now()->addMinutes($this->ttlMinutes);

                $id = DB::table('ptero_resource_reservations')->insertGetId([
                    'token' => $token,
                    'idempotency_key' => $idempotencyKey,
                    'cart_item_id' => $cartItemId,
                    'user_id' => $userId,
                    'node_id' => $node['node_id'],
                    'location_id' => $locationId,
                    'memory' => $resources['memory'],
                    'cpu' => $resources['cpu'],
                    'disk' => $resources['disk'],
                    'calculated_price' => 0,
                    'pricing_breakdown' => json_encode([]),
                    'status' => 'pending',
                    'expires_at' => $expiresAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->safeAudit('created', 'reservation', $id, [
                    'token_prefix' => substr($token, 0, 8) . '...',
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'node_id' => $node['node_id'],
                    'memory' => $resources['memory'],
                    'cpu' => $resources['cpu'],
                    'disk' => $resources['disk'],
                    'price' => 0,
                    'cart_item_id' => $cartItemId,
                ]);

                return $this->presentReservation((object) [
                    'id' => $id,
                    'token' => $token,
                    'node_id' => $node['node_id'],
                    'node_name' => $node['name'] ?? null,
                    'expires_at' => $expiresAt,
                    'calculated_price' => 0,
                    'pricing_breakdown' => [],
                    'status' => 'pending',
                ]);
            }, 5); // 5 retry attempts for deadlock
        } catch (QueryException $exception) {
            if ($this->isActiveIdempotencyDuplicate($exception, $userId, $idempotencyKey)) {
                $existingReservation = $this->getActiveByIdempotencyKey($userId, $idempotencyKey);
                if ($existingReservation) {
                    Log::info('Returning existing reservation after duplicate idempotency insert race', [
                        'reservation_id' => $existingReservation->id,
                        'user_id' => $userId,
                        'idempotency_key' => $idempotencyKey,
                    ]);

                    return $this->presentReservation($existingReservation);
                }
            }

            throw $exception;
        }
    }

    private function getActiveByIdempotencyKey(?int $userId, string $idempotencyKey): ?object
    {
        return DB::table('ptero_resource_reservations')
            ->when($userId === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $userId))
            ->where('idempotency_key', $idempotencyKey)
            ->where(function ($query) {
                $query->where('status', 'confirmed')
                    ->orWhere(function ($subQuery) {
                        $subQuery->where('status', 'pending')
                            ->where('expires_at', '>', now());
                    });
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Confirm a reservation (after successful payment)
     *
     * @param  User|null  $actor  Authenticated user performing the action, or null for system context.
     *                             When provided, authorization is enforced via ResourceReservationPolicy.
     *                             Controller callers MUST pass the actor; system callers MAY pass null.
     */
    public function confirm(string $token, int $serviceId, ?User $actor = null): bool
    {
        $reservation = $this->getByToken($token);

        if ($actor !== null) {
            $reservationModel = ResourceReservation::query()->where('token', $token)->first();

            if ($reservationModel !== null) {
                Gate::forUser($actor)->authorize('confirm', $reservationModel);
            }
        }

        $reservationId = $reservation?->id ?? 0;

        $rows = DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->update([
                'status' => 'confirmed',
                'service_id' => $serviceId,
                'updated_at' => now(),
            ]);

        if ($rows > 0) {
            $this->safeAudit('reservation_confirmed', 'resource_reservation', $reservationId, [
                'token_prefix' => substr($token, 0, 8),
                'service_id' => $serviceId,
                'node_id' => $reservation->node_id ?? null,
            ]);
        }

        return $rows > 0;
    }

    /**
     * Cancel a reservation
     *
     * @param  User|null  $actor  Authenticated user performing the action, or null for system context.
     *                             When provided, authorization is enforced via ResourceReservationPolicy.
     *                             Controller callers MUST pass the actor; system callers MAY pass null.
     */
    public function cancel(string $token, ?string $reason = null, string $source = 'system', ?User $actor = null): bool
    {
        $reservation = $this->getByToken($token);

        if (!$reservation) {
            return false;
        }

        if ($actor !== null) {
            $reservationModel = ResourceReservation::query()->where('token', $token)->first();

            if ($reservationModel !== null) {
                Gate::forUser($actor)->authorize('cancel', $reservationModel);
            }
        }

        $result = DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'admin_notes' => $reason,
                'updated_at' => now(),
            ]) > 0;

        if ($result) {
            $this->safeAudit('reservation_cancelled', 'resource_reservation', $reservation->id, [
                'token_prefix' => substr($token, 0, 8),
                'node_id' => $reservation->node_id ?? null,
            ]);
        }

        return $result;
    }

    /**
     * Extend reservation TTL
     *
     * @param  User|null  $actor  Authenticated user performing the action, or null for system context.
     *                             When provided, authorization is enforced via ResourceReservationPolicy.
     *                             Controller callers MUST pass the actor; system callers MAY pass null.
     */
    public function extend(string $token, int $additionalMinutes = 15, ?User $actor = null): bool
    {
        $reservation = $this->getByToken($token);

        if ($actor !== null) {
            $reservationModel = ResourceReservation::query()->where('token', $token)->first();

            if ($reservationModel !== null) {
                Gate::forUser($actor)->authorize('extend', $reservationModel);
            }
        }

        $reservationId = $reservation?->id ?? 0;

        $rows = DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->update([
                'expires_at' => DB::raw("DATE_ADD(expires_at, INTERVAL {$additionalMinutes} MINUTE)"),
                'updated_at' => now(),
            ]);

        if ($rows > 0) {
            $this->safeAudit('reservation_extended', 'resource_reservation', $reservationId, [
                'token_prefix' => substr($token, 0, 8),
                'additional_minutes' => $additionalMinutes,
                'node_id' => $reservation->node_id ?? null,
            ]);
        }

        return $rows > 0;
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
     * Get all reservations as a paginatable Eloquent builder (for admin API).
     *
     * Does NOT modify getAll() — callers of that method are unaffected.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<ResourceReservation>
     */
    public function queryAll(array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = ResourceReservation::query();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }
        if (!empty($filters['node_id'])) {
            $query->where('node_id', (int) $filters['node_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
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
        $count = DB::table('ptero_resource_reservations')
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);

        if ($count > 0) {
            $this->safeAudit('reservations_expired_batch', 'resource_reservation', 0, [
                'count' => $count,
                'run_at' => now()->toIso8601String(),
            ]);
        }

        return $count;
    }

    private function presentReservation(object $reservation): array
    {
        $expiresAt = $reservation->expires_at ? Carbon::parse($reservation->expires_at) : null;

        return [
            'id' => $reservation->id,
            'token' => $reservation->token,
            'node_id' => $reservation->node_id,
            'node_name' => $reservation->node_name ?? null,
            'expires_at' => $expiresAt?->toIso8601String(),
            'ttl_minutes' => $expiresAt && $reservation->status === 'pending'
                ? max(0, now()->diffInMinutes($expiresAt, false))
                : 0,
            'pricing' => [
                'total' => (float) $reservation->calculated_price,
                'breakdown' => is_array($reservation->pricing_breakdown)
                    ? $reservation->pricing_breakdown
                    : json_decode($reservation->pricing_breakdown ?? '[]', true) ?? [],
                'model' => 'stored',
            ],
            'status' => $reservation->status,
        ];
    }

    private function expireStaleIdempotencyReservations(?int $userId, string $idempotencyKey): void
    {
        DB::table('ptero_resource_reservations')
            ->when($userId === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $userId))
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }

    private function isActiveIdempotencyDuplicate(QueryException $exception, ?int $userId, ?string $idempotencyKey): bool
    {
        if ($userId === null || $idempotencyKey === null) {
            return false;
        }

        return str_contains($exception->getMessage(), 'ptero_reservations_active_idempotency_unique')
            || ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'Duplicate entry'));
    }
}
