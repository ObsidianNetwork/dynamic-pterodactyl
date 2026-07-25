<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Exceptions\DisplayException;
use App\Models\CartItem;
use App\Models\Extension;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\Concerns\AuditsExtensionActions;

class ReservationService
{
    use AuditsExtensionActions;

    private const PROVISIONING_LEASE_MINUTES = 5;

    private NodeSelectionService $nodeService;

    private ReservationConfigurationService $configurationService;

    private int $ttlMinutes;

    public function __construct(
        NodeSelectionService $nodeService,
        ReservationConfigurationService $configurationService
    ) {
        $this->nodeService = $nodeService;
        $this->configurationService = $configurationService;

        $config = Extension::where('extension', 'DynamicPterodactyl')
            ->where('enabled', true)
            ->first()
            ?->settings
            ->pluck('value', 'key')
            ->toArray() ?? [];
        $this->ttlMinutes = (int) ($config['reservation_ttl'] ?? 15);
    }

    /**
     * Create or refresh the one server-owned hold for a cart item.
     *
     * Browser-provided tokens and idempotency keys are deliberately not accepted.
     *
     * @return array{id: int, node_id: int, expires_at: string, status: string}
     */
    public function reserveForCartItem(CartItem $cartItem): array
    {
        $snapshot = $this->configurationService->forCartItem($cartItem);
        $ownerId = $snapshot['customer_id'];

        return DB::transaction(function () use ($cartItem, $snapshot, $ownerId) {
            DB::table('ptero_resource_reservations')
                ->where('location_id', $snapshot['location_id'])
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            $existing = DB::table('ptero_resource_reservations')
                ->where('cart_item_id', $cartItem->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->user_id !== null && (int) $existing->user_id !== (int) $ownerId) {
                throw new DisplayException('This cart capacity hold belongs to a different customer.');
            }

            if ($existing !== null && Carbon::parse($existing->expires_at)->isPast()) {
                DB::table('ptero_resource_reservations')
                    ->where('id', $existing->id)
                    ->update([
                        'status' => 'expired',
                        'updated_at' => now(),
                    ]);
                $existing = null;
            }

            if ($existing !== null && $this->matchesSnapshot($existing, $snapshot)) {
                $expiresAt = now()->addMinutes($this->ttlMinutes);

                DB::table('ptero_resource_reservations')
                    ->where('id', $existing->id)
                    ->update([
                        'user_id' => $ownerId,
                        'expires_at' => $expiresAt,
                        'updated_at' => now(),
                    ]);

                return [
                    'id' => (int) $existing->id,
                    'node_id' => (int) $existing->node_id,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'status' => 'pending',
                ];
            }

            $excludedToken = $existing?->token;
            if ($existing !== null) {
                DB::table('ptero_resource_reservations')
                    ->where('id', $existing->id)
                    ->update([
                        'status' => 'cancelled',
                        'admin_notes' => 'Replaced after cart configuration changed.',
                        'updated_at' => now(),
                    ]);
            }

            $node = $this->nodeService->selectBestNode(
                $snapshot['location_id'],
                $snapshot['resources'],
                $excludedToken
            );

            if ($node === null) {
                throw new DisplayException('No node has enough capacity for this configuration.');
            }

            $payload = $this->configurationService->withNode($snapshot, (int) $node['node_id']);
            $fingerprint = $this->configurationService->fingerprint($payload);
            $token = Str::random(64);
            $expiresAt = now()->addMinutes($this->ttlMinutes);

            $id = DB::table('ptero_resource_reservations')->insertGetId([
                'token' => $token,
                'idempotency_key' => null,
                'cart_item_id' => $cartItem->id,
                'cart_id' => $snapshot['cart_id'],
                'server_extension_id' => $snapshot['server_extension_id'],
                'panel_identity' => $snapshot['panel_identity'],
                'service_id' => null,
                'user_id' => $ownerId,
                'product_id' => $snapshot['product_id'],
                'plan_id' => $snapshot['plan_id'],
                'quantity' => $snapshot['quantity'],
                'currency_code' => $snapshot['currency_code'],
                'configuration_fingerprint' => $fingerprint,
                'configuration_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'pricing_version' => $snapshot['pricing_version'],
                'formula_version' => $snapshot['formula_version'],
                'node_id' => $node['node_id'],
                'location_id' => $snapshot['location_id'],
                'memory' => $snapshot['resources']['memory'],
                'cpu' => $snapshot['resources']['cpu'],
                'disk' => $snapshot['resources']['disk'],
                'calculated_price' => $snapshot['calculated_price'],
                'pricing_breakdown' => json_encode([], JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->safeAudit('created', 'reservation', $id, [
                'reservation_id' => $id,
                'configuration_fingerprint' => $fingerprint,
                'product_id' => $snapshot['product_id'],
                'plan_id' => $snapshot['plan_id'],
                'location_id' => $snapshot['location_id'],
                'node_id' => $node['node_id'],
                'memory' => $snapshot['resources']['memory'],
                'cpu' => $snapshot['resources']['cpu'],
                'disk' => $snapshot['resources']['disk'],
                'cart_item_id' => $cartItem->id,
            ]);

            return [
                'id' => $id,
                'node_id' => (int) $node['node_id'],
                'expires_at' => $expiresAt->toIso8601String(),
                'status' => 'pending',
            ];
        }, 5);
    }

    /**
     * Transfer every guest hold with the cart inside the login transaction.
     */
    public function transferCartOwnership(int $cartId, int $userId): int
    {
        return DB::transaction(function () use ($cartId, $userId) {
            $reservations = DB::table('ptero_resource_reservations')
                ->where('cart_id', $cartId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                if ($reservation->user_id !== null && (int) $reservation->user_id !== $userId) {
                    throw new \RuntimeException('A capacity hold in this cart belongs to a different customer.');
                }

                if ($reservation->user_id !== null) {
                    continue;
                }

                $payload = json_decode(
                    (string) $reservation->configuration_payload,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (! is_array($payload) || (int) ($payload['cart_id'] ?? 0) !== $cartId) {
                    throw new \RuntimeException('Capacity hold payload does not match its cart.');
                }

                $payload = $this->configurationService->withCustomer($payload, $userId);

                DB::table('ptero_resource_reservations')
                    ->where('id', $reservation->id)
                    ->update([
                        'user_id' => $userId,
                        'configuration_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'configuration_fingerprint' => $this->configurationService->fingerprint($payload),
                        'updated_at' => now(),
                    ]);
            }

            return $reservations->whereNull('user_id')->count();
        }, 5);
    }

    /**
     * Attach the validated cart hold to the newly created service.
     */
    public function bindCartItemToService(
        CartItem $cartItem,
        Service $service,
        User $user,
        CarbonInterface $holdUntil
    ): void {
        $snapshot = $this->configurationService->forCartItem($cartItem);

        DB::transaction(function () use ($cartItem, $service, $user, $holdUntil, $snapshot) {
            $reservation = DB::table('ptero_resource_reservations')
                ->where('cart_item_id', $cartItem->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($reservation === null || Carbon::parse($reservation->expires_at)->isPast()) {
                throw new DisplayException('Capacity hold expired during checkout. Please reconfigure this product.');
            }

            if ($reservation->user_id !== null && (int) $reservation->user_id !== (int) $user->id) {
                throw new DisplayException('This capacity hold belongs to a different customer.');
            }

            if ($reservation->service_id !== null && (int) $reservation->service_id !== (int) $service->id) {
                throw new \RuntimeException('This capacity hold is already bound to another service.');
            }

            if (! $this->matchesSnapshot($reservation, $snapshot)) {
                throw new DisplayException('The cart configuration changed after capacity was reserved. Please reconfigure this product.');
            }

            if (
                (int) $service->product_id !== (int) $reservation->product_id
                || (int) $service->plan_id !== (int) $reservation->plan_id
                || (int) $service->quantity !== (int) $reservation->quantity
                || strtoupper((string) $service->currency_code) !== strtoupper((string) $reservation->currency_code)
            ) {
                throw new \RuntimeException('The new service does not match its capacity reservation.');
            }

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'service_id' => $service->id,
                    'user_id' => $user->id,
                    'expires_at' => $holdUntil,
                    'updated_at' => now(),
                ]);

            $this->safeAudit('reservation_bound', 'resource_reservation', $reservation->id, [
                'service_id' => $service->id,
                'user_id' => $user->id,
                'configuration_fingerprint' => $reservation->configuration_fingerprint,
            ]);
        }, 5);
    }

    /**
     * Lock and validate a hold immediately before the external create request.
     *
     * @return array{
     *     reservation_id: int,
     *     panel_identity: string,
     *     node_id: int,
     *     location_id: int,
     *     memory: int,
     *     cpu: int,
     *     disk: int,
     *     provisioning_lease_id: string|null,
     *     already_consumed: bool
     * }|null
     */
    public function beginProvisioning(Service $service): ?array
    {
        return DB::transaction(function () use ($service) {
            $reservation = DB::table('ptero_resource_reservations')
                ->where('service_id', $service->id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                if ($this->configurationService->requiresReservation($service->product_id)) {
                    throw new \RuntimeException('Dynamic resource service has no capacity reservation.');
                }

                return null;
            }

            if ($reservation->status === 'confirmed') {
                return $this->provisioningContext($reservation, true);
            }

            if ($reservation->status !== 'pending') {
                throw new \RuntimeException("Capacity reservation is {$reservation->status}.");
            }

            if (Carbon::parse($reservation->expires_at)->isPast()) {
                throw new \RuntimeException('Capacity reservation expired before provisioning.');
            }

            if (
                $reservation->provisioning_started_at !== null
                && Carbon::parse($reservation->provisioning_started_at)
                    ->greaterThan(now()->subMinutes(self::PROVISIONING_LEASE_MINUTES))
            ) {
                throw new \RuntimeException('Capacity reservation is already being provisioned.');
            }

            $this->configurationService->assertServiceMatches($service, $reservation);

            $leaseExpiresAt = now()->addMinutes(self::PROVISIONING_LEASE_MINUTES);
            $currentExpiresAt = Carbon::parse($reservation->expires_at);
            $leaseId = Str::random(64);

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'provisioning_started_at' => now(),
                    'provisioning_lease_id' => $leaseId,
                    'expires_at' => $currentExpiresAt->greaterThan($leaseExpiresAt)
                        ? $currentExpiresAt
                        : $leaseExpiresAt,
                    'last_provisioning_error' => null,
                    'updated_at' => now(),
                ]);

            return $this->provisioningContext($reservation, false, $leaseId);
        }, 5);
    }

    public function completeProvisioning(int $serviceId, ?string $leaseId = null): bool
    {
        return DB::transaction(function () use ($serviceId, $leaseId) {
            $reservation = DB::table('ptero_resource_reservations')
                ->where('service_id', $serviceId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return false;
            }

            if ($reservation->status === 'confirmed') {
                return true;
            }

            if ($reservation->status !== 'pending') {
                throw new \RuntimeException("Cannot consume a {$reservation->status} capacity reservation.");
            }

            if (
                $leaseId === null
                || $reservation->provisioning_lease_id === null
                || ! hash_equals((string) $reservation->provisioning_lease_id, $leaseId)
            ) {
                throw new \RuntimeException('Provisioning lease no longer owns this capacity reservation.');
            }

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => 'confirmed',
                    'consumed_at' => now(),
                    'provisioning_started_at' => null,
                    'provisioning_lease_id' => null,
                    'last_provisioning_error' => null,
                    'updated_at' => now(),
                ]);

            $this->safeAudit('reservation_consumed', 'resource_reservation', $reservation->id, [
                'service_id' => $serviceId,
                'node_id' => $reservation->node_id,
                'configuration_fingerprint' => $reservation->configuration_fingerprint,
            ]);

            return true;
        }, 5);
    }

    public function failProvisioning(
        int $serviceId,
        ?string $leaseId,
        \Throwable $exception
    ): void
    {
        DB::table('ptero_resource_reservations')
            ->where('service_id', $serviceId)
            ->where('status', 'pending')
            ->where('provisioning_lease_id', $leaseId)
            ->update([
                'provisioning_started_at' => null,
                'provisioning_lease_id' => null,
                'last_provisioning_error' => Str::limit($exception->getMessage(), 1000, ''),
                'updated_at' => now(),
            ]);
    }

    public function cancelForCartItem(int $cartItemId): bool
    {
        return DB::transaction(function () use ($cartItemId) {
            $reservation = DB::table('ptero_resource_reservations')
                ->where('cart_item_id', $cartItemId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($reservation === null || $reservation->service_id !== null) {
                return false;
            }

            return DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => 'cancelled',
                    'admin_notes' => 'Cart item removed.',
                    'updated_at' => now(),
                ]) > 0;
        }, 5);
    }

    /**
     * Admin/system cancellation by the opaque internal token.
     */
    public function cancel(
        string $token,
        ?string $reason = null,
        string $source = 'system',
        ?User $actor = null
    ): bool {
        $reservation = $this->getByToken($token);
        if ($reservation === null) {
            return false;
        }

        if ($actor !== null) {
            $reservationModel = ResourceReservation::query()->where('token', $token)->first();
            if ($reservationModel !== null) {
                Gate::forUser($actor)->authorize('cancel', $reservationModel);
            }
        }

        $updated = DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->whereNull('service_id')
            ->update([
                'status' => 'cancelled',
                'admin_notes' => $reason,
                'updated_at' => now(),
            ]) > 0;

        if ($updated) {
            $this->safeAudit('reservation_cancelled', 'resource_reservation', $reservation->id, [
                'source' => $source,
                'node_id' => $reservation->node_id,
            ]);
        }

        return $updated;
    }

    /**
     * Admin-only TTL extension.
     */
    public function extend(string $token, int $additionalMinutes = 15, ?User $actor = null): bool
    {
        $reservation = ResourceReservation::query()->where('token', $token)->first();
        if ($reservation === null) {
            return false;
        }

        if ($actor !== null) {
            Gate::forUser($actor)->authorize('extend', $reservation);
        }

        if ($reservation->status !== 'pending') {
            return false;
        }

        $reservation->expires_at = $reservation->expires_at->addMinutes($additionalMinutes);
        $reservation->save();

        $this->safeAudit('reservation_extended', 'resource_reservation', $reservation->id, [
            'additional_minutes' => $additionalMinutes,
            'node_id' => $reservation->node_id,
        ]);

        return true;
    }

    public function getByToken(string $token): ?object
    {
        return DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->first();
    }

    public function getByCartItem(int $cartItemId): ?object
    {
        return DB::table('ptero_resource_reservations')
            ->where('cart_item_id', $cartItemId)
            ->where('status', 'pending')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<ResourceReservation>
     */
    public function queryAll(array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = ResourceReservation::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }
        if (! empty($filters['node_id'])) {
            $query->where('node_id', (int) $filters['node_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

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

        $average = DB::table('ptero_resource_reservations')
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
                'memory' => round($average->avg_memory ?? 0),
                'cpu' => round($average->avg_cpu ?? 0),
                'disk' => round($average->avg_disk ?? 0),
            ],
        ];
    }

    public function cleanupExpired(): int
    {
        $count = DB::table('ptero_resource_reservations')
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'provisioning_started_at' => null,
                'provisioning_lease_id' => null,
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

    /**
     * @param  object  $reservation
     * @param  array<string, mixed>  $snapshot
     */
    private function matchesSnapshot(object $reservation, array $snapshot): bool
    {
        $payload = $this->configurationService->withNode($snapshot, (int) $reservation->node_id);

        return hash_equals(
            (string) $reservation->configuration_fingerprint,
            $this->configurationService->fingerprint($payload)
        );
    }

    /**
     * @param  object  $reservation
     * @return array{
     *     reservation_id: int,
     *     panel_identity: string,
     *     node_id: int,
     *     location_id: int,
     *     memory: int,
     *     cpu: int,
     *     disk: int,
     *     provisioning_lease_id: string|null,
     *     already_consumed: bool
     * }
     */
    private function provisioningContext(
        object $reservation,
        bool $alreadyConsumed,
        ?string $leaseId = null
    ): array
    {
        return [
            'reservation_id' => (int) $reservation->id,
            'panel_identity' => (string) $reservation->panel_identity,
            'node_id' => (int) $reservation->node_id,
            'location_id' => (int) $reservation->location_id,
            'memory' => (int) $reservation->memory,
            'cpu' => (int) $reservation->cpu,
            'disk' => (int) $reservation->disk,
            'provisioning_lease_id' => $leaseId,
            'already_consumed' => $alreadyConsumed,
        ];
    }
}
