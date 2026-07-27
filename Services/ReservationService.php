<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Exceptions\DisplayException;
use App\Exceptions\PermanentProvisioningException;
use App\Models\CartItem;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Service\CapacityConfigurationLockService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ProductStockService;
use App\Services\Service\ServiceJobDispatchService;
use App\Support\StrictInteger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ReservationAllocation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\Concerns\AuditsExtensionActions;

class ReservationService
{
    use AuditsExtensionActions;

    private const MAX_ADMIN_EXTENSION_MINUTES = 60;

    private const PROVISIONING_LEASE_MINUTES = 5;

    private const RETRY_DELAYS_SECONDS = [15, 60, 300, 900, 1800, 3600, 10800];

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
        return DB::transaction(function () use ($cartItem) {
            // Product is the shared first lock for configuration readers and
            // writers. Build the snapshot only after the complete
            // Product→option→plan→price lock set is held, then retain those
            // locks until the capacity hold has been inserted.
            $product = app(CapacityConfigurationLockService::class)
                ->lockProduct((int) $cartItem->product_id);
            $cartItem = CartItem::query()
                ->whereKey($cartItem->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $cartItem->product_id !== (int) $product->id) {
                throw new \RuntimeException(
                    'The cart product changed while its capacity configuration was being locked.'
                );
            }
            $plan = $product->plans->firstWhere(
                'id',
                (int) $cartItem->plan_id
            );
            if ($plan === null) {
                throw new DisplayException(
                    'The selected product plan is no longer available.'
                );
            }
            $cartItem->setRelation('product', $product);
            $cartItem->setRelation('plan', $plan);
            $cartItem->loadMissing('cart');

            $snapshot = $this->configurationService
                ->forCartItem($cartItem);
            $ownerId = $snapshot['customer_id'];
            $previousScope = DB::table('ptero_resource_reservations')
                ->where('cart_item_id', $cartItem->id)
                ->where('status', ResourceReservation::STATUS_PENDING)
                ->first(['panel_identity', 'location_id']);
            $scopes = collect([
                [
                    'panel_identity' => (string) $snapshot['panel_identity'],
                    'location_id' => (int) $snapshot['location_id'],
                ],
                $previousScope !== null && $previousScope->panel_identity !== null
                    ? [
                        'panel_identity' => (string) $previousScope->panel_identity,
                        'location_id' => (int) $previousScope->location_id,
                    ]
                    : null,
            ])
                ->filter()
                ->unique(fn (array $scope) => $this->capacityScopeKey($scope))
                ->sortBy(fn (array $scope) => $this->capacityScopeKey($scope))
                ->values();

            foreach ($scopes as $scope) {
                DB::table('ptero_capacity_scopes')->insertOrIgnore([
                    ...$scope,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach ($scopes as $scope) {
                DB::table('ptero_capacity_scopes')
                    ->where('panel_identity', $scope['panel_identity'])
                    ->where('location_id', $scope['location_id'])
                    ->lockForUpdate()
                    ->first();
            }
            foreach ($scopes as $scope) {
                DB::table('ptero_resource_reservations')
                    ->where('panel_identity', $scope['panel_identity'])
                    ->where('location_id', $scope['location_id'])
                    ->where(function (Builder $query) {
                        $this->applyCapacityHoldingScope($query);
                    })
                    ->lockForUpdate()
                    ->get();
            }

            $existing = DB::table('ptero_resource_reservations')
                ->where('cart_item_id', $cartItem->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if (
                $existing !== null
                && ! $scopes->contains(
                    fn (array $scope) => $this->capacityScopeKey($scope)
                        === $this->capacityScopeKey([
                            'panel_identity' => (string) $existing->panel_identity,
                            'location_id' => (int) $existing->location_id,
                        ])
                )
            ) {
                throw new \RuntimeException(
                    'The capacity scope changed concurrently; retry the cart update.'
                );
            }

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
                $this->releaseAllocationClaims((int) $existing->id);
                $existing = null;
            }

            if ($existing !== null && $this->matchesSnapshot($existing, $snapshot)) {
                $this->assertAllocationClaimsMatch($existing);
                $expiresAt = now()->addMinutes($this->ttlMinutes);

                DB::table('ptero_resource_reservations')
                    ->where('id', $existing->id)
                    ->update([
                        'user_id' => $ownerId,
                        'expires_at' => $expiresAt,
                        'guaranteed_until' => $expiresAt,
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
                $this->releaseAllocationClaims((int) $existing->id);
            }

            $requiredAllocations = max(
                1,
                (int) data_get($snapshot, 'allocation_requirements.required_count', 1)
            );
            $requiredPorts = collect(
                data_get($snapshot, 'allocation_requirements.mappings', [])
            )
                ->pluck('requested_port')
                ->filter(fn ($port) => $port !== null)
                ->map(fn ($port) => (int) $port)
                ->values()
                ->all();
            $node = method_exists($this->nodeService, 'selectBestNodeWithAllocations')
                ? $this->nodeService->selectBestNodeWithAllocations(
                    $snapshot['location_id'],
                    $snapshot['resources'],
                    $requiredAllocations,
                    $excludedToken,
                    $requiredPorts,
                    (array) data_get(
                        $snapshot,
                        'allocation_requirements.allowed_port_ranges',
                        []
                    ),
                    (bool) data_get(
                        $snapshot,
                        'allocation_requirements.dedicated_ip',
                        false
                    )
                )
                : $this->nodeService->selectBestNode(
                    $snapshot['location_id'],
                    $snapshot['resources'],
                    $excludedToken
                );

            if ($node === null) {
                throw new DisplayException('No node has enough capacity for this configuration.');
            }

            $availableAllocations = array_values(
                $node['selected_allocations'] ?? $node['available_allocations'] ?? []
            );
            if (count($availableAllocations) < $requiredAllocations) {
                throw new DisplayException(
                    'No node has enough unassigned allocations for this configuration.'
                );
            }

            $selectedAllocations = $this->mapAllocationRequirements(
                $availableAllocations,
                (array) data_get($snapshot, 'allocation_requirements.mappings', [])
            );
            $payload = $this->configurationService->withPlacement(
                $snapshot,
                (int) $node['node_id'],
                $selectedAllocations
            );
            $fingerprint = $this->configurationService->fingerprint($payload);
            $token = Str::random(64);
            $expiresAt = now()->addMinutes($this->ttlMinutes);

            $id = DB::table('ptero_resource_reservations')->insertGetId([
                'token' => $token,
                'idempotency_key' => null,
                'cart_item_id' => $cartItem->id,
                'cart_item_guard_id' => $cartItem->id,
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
                'guaranteed_until' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($selectedAllocations as $allocation) {
                ReservationAllocation::query()->create([
                    'reservation_id' => $id,
                    'panel_identity' => $snapshot['panel_identity'],
                    'node_id' => (int) $node['node_id'],
                    'allocation_id' => (int) $allocation['allocation_id'],
                    'ip' => $allocation['ip'] ?: null,
                    'port' => (int) $allocation['port'],
                    'environment_key' => $allocation['environment_key'],
                    'is_primary' => (bool) $allocation['is_primary'],
                ]);
            }

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
            $user = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();
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

                $payload = $this->configurationService->withCustomer(
                    $payload,
                    $userId,
                    (string) $user->email
                );

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
        CarbonInterface $holdUntil,
        ?Invoice $invoice = null
    ): void {
        $snapshot = $this->configurationService->forCartItem($cartItem);

        DB::transaction(function () use ($cartItem, $service, $user, $holdUntil, $invoice, $snapshot) {
            $lockedInvoice = null;
            if ($invoice !== null) {
                $lockedInvoice = Invoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            $lockedService = Service::query()
                ->whereKey($service->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reservation = DB::table('ptero_resource_reservations')
                ->where('cart_item_id', $cartItem->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            $invoiceItems = $lockedInvoice === null
                ? collect()
                : DB::table('invoice_items')
                    ->where('invoice_id', $lockedInvoice->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            if ($reservation === null || Carbon::parse($reservation->expires_at)->isPast()) {
                throw new DisplayException('Capacity hold expired during checkout. Please reconfigure this product.');
            }

            if ($reservation->user_id !== null && (int) $reservation->user_id !== (int) $user->id) {
                throw new DisplayException('This capacity hold belongs to a different customer.');
            }

            if ($reservation->service_id !== null && (int) $reservation->service_id !== (int) $lockedService->id) {
                throw new \RuntimeException('This capacity hold is already bound to another service.');
            }

            if (! $this->matchesSnapshot($reservation, $snapshot)) {
                throw new DisplayException('The cart configuration changed after capacity was reserved. Please reconfigure this product.');
            }

            if (
                (int) $lockedService->product_id !== (int) $reservation->product_id
                || (int) $lockedService->plan_id !== (int) $reservation->plan_id
                || (int) $lockedService->quantity !== (int) $reservation->quantity
                || strtoupper((string) $lockedService->currency_code) !== strtoupper((string) $reservation->currency_code)
            ) {
                throw new \RuntimeException('The new service does not match its capacity reservation.');
            }
            if (
                $lockedInvoice !== null
                && (
                    $lockedInvoice->status !== Invoice::STATUS_PENDING
                    || (int) $lockedInvoice->user_id !== (int) $user->id
                    || strtoupper((string) $lockedInvoice->currency_code)
                        !== strtoupper((string) $lockedService->currency_code)
                )
            ) {
                throw new \RuntimeException(
                    'The checkout invoice does not match its capacity-backed service.'
                );
            }
            if (
                $lockedInvoice !== null
                && ! $invoiceItems->contains(
                    fn ($item): bool => (int) $item->reference_id === (int) $lockedService->id
                        && (string) $item->reference_type === $lockedService->getMorphClass()
                        && (int) $item->quantity === (int) $lockedService->quantity
                )
            ) {
                throw new \RuntimeException(
                    'The checkout invoice is missing the immutable capacity-backed service line.'
                );
            }

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'service_id' => $lockedService->id,
                    'service_guard_id' => $lockedService->id,
                    'invoice_id' => $lockedInvoice?->id,
                    'user_id' => $user->id,
                    'expires_at' => $holdUntil,
                    'guaranteed_until' => $holdUntil,
                    'updated_at' => now(),
                ]);

            $this->safeAudit('reservation_bound', 'resource_reservation', $reservation->id, [
                'service_id' => $lockedService->id,
                'invoice_id' => $lockedInvoice?->id,
                'user_id' => $user->id,
                'configuration_fingerprint' => $reservation->configuration_fingerprint,
            ]);
        }, 5);
    }

    /**
     * Turn an invoice-backed hold into a durable, non-expiring fulfillment
     * commitment. The caller is responsible for running this inside the same
     * transaction that marks the invoice paid.
     */
    public function preflightPaidService(
        Service $service,
        Invoice $invoice
    ): ?string {
        try {
            $hasBoundReservation = $this->hasCheckoutReservation(
                (int) $service->id
            );
            if (
                ! $hasBoundReservation
                && ! $this->configurationService->requiresReservation(
                    (int) $service->product_id
                )
            ) {
                return null;
            }

            $this->configurationService->assertExclusiveProvisioningControl();

            DB::transaction(function () use ($service, $invoice): void {
                $lockedInvoice = Invoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedService = Service::query()
                    ->whereKey($service->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $reservation = $this->checkoutCommitmentQuery(
                    $lockedService->id
                )
                    ->lockForUpdate()
                    ->first();
                if ($reservation === null) {
                    throw new \RuntimeException(
                        'A paid dynamic resource service has no capacity reservation.'
                    );
                }
                if (
                    $reservation->invoice_id !== null
                    && (int) $reservation->invoice_id
                        !== (int) $lockedInvoice->id
                ) {
                    throw new \RuntimeException(
                        'The paid invoice does not own this capacity commitment.'
                    );
                }
                if (in_array($reservation->status, [
                    ResourceReservation::STATUS_PAID_COMMITTED,
                    ResourceReservation::STATUS_CONFIRMED,
                ], true)) {
                    return;
                }
                if ($reservation->status !== ResourceReservation::STATUS_PENDING) {
                    throw new \RuntimeException(
                        "Cannot commit a {$reservation->status} capacity reservation after payment."
                    );
                }

                $guaranteedUntil = Carbon::parse(
                    $reservation->guaranteed_until
                        ?? $reservation->expires_at
                );
                if ($guaranteedUntil->isPast()) {
                    throw new \RuntimeException(
                        'The seven-day capacity guarantee expired before payment completed.'
                    );
                }

                $this->assertInvoiceLineMatchesReservation(
                    $lockedService,
                    $lockedInvoice,
                    $reservation
                );
                $this->configurationService->assertServiceMatches(
                    $lockedService,
                    $reservation
                );
                $this->assertAllocationClaimsMatch(
                    $reservation
                );
            }, 5);

            return null;
        } catch (\Throwable $exception) {
            return "Capacity-backed service {$service->id} failed payment preflight: {$exception->getMessage()}";
        }
    }

    public function commitPaidService(Service $service, Invoice $invoice): bool
    {
        $hasBoundReservation = $this->hasCheckoutReservation((int) $service->id);
        if (
            ! $hasBoundReservation
            && ! $this->configurationService->requiresReservation((int) $service->product_id)
        ) {
            return false;
        }

        $this->configurationService->assertExclusiveProvisioningControl();

        return DB::transaction(function () use ($service, $invoice) {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedService = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $reservation = $this->checkoutCommitmentQuery($lockedService->id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw new \RuntimeException(
                    'A paid dynamic resource service has no capacity reservation.'
                );
            }

            if ($reservation->invoice_id !== null && (int) $reservation->invoice_id !== (int) $lockedInvoice->id) {
                throw new \RuntimeException('The paid invoice does not own this capacity commitment.');
            }

            if ($reservation->status === ResourceReservation::STATUS_PENDING) {
                $this->assertInvoiceLineMatchesReservation(
                    $lockedService,
                    $lockedInvoice,
                    $reservation
                );
            }

            if ($reservation->status === ResourceReservation::STATUS_CONFIRMED) {
                $cancellationRequested = $reservation->cancellation_requested_at !== null
                    || $lockedService->cancellation()->where('type', 'immediate')->exists()
                    || in_array($lockedService->status, [
                        Service::STATUS_CANCELLED,
                        Service::STATUS_CANCELLATION_PENDING,
                    ], true);
                if (! $cancellationRequested) {
                    $lockedService->status = Service::STATUS_ACTIVE;
                    $this->persistFulfillmentService($lockedService);
                }

                return true;
            }

            if ($reservation->status === ResourceReservation::STATUS_PAID_COMMITTED) {
                if (! in_array($lockedService->status, ['active', 'cancelled', 'cancellation_pending'], true)) {
                    $lockedService->status = 'provisioning';
                    $this->persistFulfillmentService($lockedService);
                }

                return true;
            }

            if ($reservation->status !== ResourceReservation::STATUS_PENDING) {
                throw new \RuntimeException(
                    "Cannot commit a {$reservation->status} capacity reservation after payment."
                );
            }

            $guaranteedUntil = Carbon::parse(
                $reservation->guaranteed_until ?? $reservation->expires_at
            );
            if ($guaranteedUntil->isPast()) {
                throw new \RuntimeException(
                    'The seven-day capacity guarantee expired before payment completed.'
                );
            }

            $this->configurationService->assertServiceMatches($lockedService, $reservation);
            $this->assertAllocationClaimsMatch($reservation);

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'invoice_id' => $lockedInvoice->id,
                    'status' => ResourceReservation::STATUS_PAID_COMMITTED,
                    'paid_committed_at' => now(),
                    'next_provisioning_attempt_at' => now(),
                    'updated_at' => now(),
                ]);

            $lockedService->status = 'provisioning';
            if ($lockedService->expires_at === null) {
                $lockedService->expires_at = $lockedService->calculateNextDueDate();
            }
            $this->persistFulfillmentService($lockedService);

            $this->safeAudit('reservation_paid_committed', 'resource_reservation', $reservation->id, [
                'service_id' => $lockedService->id,
                'invoice_id' => $lockedInvoice->id,
                'guaranteed_until' => $guaranteedUntil->toIso8601String(),
            ]);

            return true;
        }, 5);
    }

    /**
     * Free services have no invoice but require the same durable fulfillment
     * state before a queue worker may create a server.
     */
    public function commitFreeService(Service $service): bool
    {
        $hasBoundReservation = $this->hasCheckoutReservation((int) $service->id);
        if (
            ! $hasBoundReservation
            && ! $this->configurationService->requiresReservation((int) $service->product_id)
        ) {
            return false;
        }

        $this->configurationService->assertExclusiveProvisioningControl();

        return DB::transaction(function () use ($service) {
            $lockedService = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $reservation = $this->checkoutCommitmentQuery($lockedService->id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw new \RuntimeException(
                    'A free dynamic resource service has no capacity reservation.'
                );
            }

            if (! in_array($reservation->status, [
                ResourceReservation::STATUS_PENDING,
                ResourceReservation::STATUS_PAID_COMMITTED,
                ResourceReservation::STATUS_CONFIRMED,
            ], true)) {
                throw new \RuntimeException("Cannot fulfill a {$reservation->status} free-service reservation.");
            }

            $this->configurationService->assertServiceMatches($lockedService, $reservation);
            if ($reservation->status === ResourceReservation::STATUS_PENDING) {
                $this->assertAllocationClaimsMatch($reservation);
            }

            if ($reservation->status === ResourceReservation::STATUS_PENDING) {
                DB::table('ptero_resource_reservations')
                    ->where('id', $reservation->id)
                    ->update([
                        'status' => ResourceReservation::STATUS_PAID_COMMITTED,
                        'paid_committed_at' => now(),
                        'next_provisioning_attempt_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if ($reservation->status !== ResourceReservation::STATUS_CONFIRMED) {
                $lockedService->status = 'provisioning';
                $lockedService->expires_at ??= $lockedService->calculateNextDueDate();
                $this->persistFulfillmentService($lockedService);
            }

            return true;
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
     *     already_consumed: bool,
     *     nest_id: int,
     *     egg_id: int,
     *     user_external_id: string
     * }|null
     */
    public function beginProvisioning(Service $service): ?array
    {
        return DB::transaction(function () use ($service) {
            $reservation = $this->checkoutCommitmentQuery((int) $service->id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                if ($this->configurationService->requiresReservation($service->product_id)) {
                    throw new PermanentProvisioningException(
                        'Dynamic resource service has no capacity reservation.'
                    );
                }

                return null;
            }

            $this->assertAllocationClaimsMatch($reservation);

            if ($reservation->status === 'confirmed') {
                return $this->provisioningContext($reservation, true);
            }

            if ($reservation->status !== ResourceReservation::STATUS_PAID_COMMITTED) {
                throw new PermanentProvisioningException(
                    "Capacity reservation is {$reservation->status}."
                );
            }

            if ($reservation->cancellation_requested_at !== null) {
                throw new \RuntimeException('Capacity fulfillment was cancelled before provisioning.');
            }

            if (
                $reservation->provisioning_started_at !== null
                && Carbon::parse($reservation->provisioning_started_at)
                    ->greaterThan(now()->subMinutes(self::PROVISIONING_LEASE_MINUTES))
            ) {
                throw new \RuntimeException('Capacity reservation is already being provisioned.');
            }

            try {
                $this->configurationService->assertServiceMatches($service, $reservation);
            } catch (\RuntimeException $exception) {
                throw new PermanentProvisioningException(
                    $exception->getMessage(),
                    (int) $exception->getCode(),
                    $exception
                );
            }

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
                    'provisioning_attempts' => (int) $reservation->provisioning_attempts + 1,
                    'last_provisioning_attempt_at' => now(),
                    'next_provisioning_attempt_at' => null,
                    'last_provisioning_error' => null,
                    'updated_at' => now(),
                ]);

            return $this->provisioningContext($reservation, false, $leaseId);
        }, 5);
    }

    /**
     * Confirm the exact external server and activate the Paymenter service.
     * A false result means cancellation won the race and the caller must delete
     * the external server rather than exposing it to the customer.
     *
     * @param  array<string, mixed>  $externalServer
     */
    public function completeProvisioning(
        int|Service $service,
        ?string $leaseId = null,
        array $externalServer = []
    ): bool {
        $serviceId = $service instanceof Service ? (int) $service->id : $service;

        return DB::transaction(function () use ($serviceId, $leaseId, $externalServer) {
            $lockedService = Service::query()->whereKey($serviceId)->lockForUpdate()->firstOrFail();
            $reservation = $this->checkoutCommitmentQuery($serviceId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return false;
            }

            if ($reservation->status === 'confirmed') {
                $alreadyCancelled = $reservation->cancellation_requested_at !== null
                    || $lockedService->cancellation()->where('type', 'immediate')->exists()
                    || $lockedService->status === 'cancelled';
                if (! $alreadyCancelled) {
                    $lockedService->status = Service::STATUS_ACTIVE;
                    $this->persistFulfillmentService($lockedService);
                }

                return ! $alreadyCancelled;
            }

            if ($reservation->status !== ResourceReservation::STATUS_PAID_COMMITTED) {
                throw new \RuntimeException("Cannot consume a {$reservation->status} capacity reservation.");
            }

            if (
                $leaseId === null
                || $reservation->provisioning_lease_id === null
                || ! hash_equals((string) $reservation->provisioning_lease_id, $leaseId)
            ) {
                throw new \RuntimeException('Provisioning lease no longer owns this capacity reservation.');
            }

            $cancellationRequested = $reservation->cancellation_requested_at !== null
                || $lockedService->status === 'cancelled'
                || $lockedService->cancellation()->where('type', 'immediate')->exists();
            $externalAttributes = $externalServer['attributes'] ?? $externalServer;
            $externalIdentity = $this->externalServerIdentity($externalAttributes);

            if ($cancellationRequested) {
                DB::table('ptero_resource_reservations')
                    ->where('id', $reservation->id)
                    ->update([
                        'cancellation_requested_at' => $reservation->cancellation_requested_at ?? now(),
                        ...$externalIdentity,
                        'last_reconciled_at' => now(),
                        'provisioning_started_at' => null,
                        'provisioning_lease_id' => null,
                        'updated_at' => now(),
                    ]);
                $lockedService->status = 'cancellation_pending';
                $this->persistFulfillmentService($lockedService);

                return false;
            }

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => 'confirmed',
                    'consumed_at' => now(),
                    ...$externalIdentity,
                    'last_reconciled_at' => now(),
                    'provisioning_started_at' => null,
                    'provisioning_lease_id' => null,
                    'last_provisioning_error' => null,
                    'updated_at' => now(),
                ]);

            $this->releaseAllocationClaims((int) $reservation->id);
            $lockedService->status = Service::STATUS_ACTIVE;
            $this->persistFulfillmentService($lockedService);

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
    ): void {
        $attempts = (int) $this->checkoutCommitmentQuery($serviceId)
            ->value('provisioning_attempts');
        $delay = self::RETRY_DELAYS_SECONDS[
            min(max(0, $attempts - 1), count(self::RETRY_DELAYS_SECONDS) - 1)
        ];

        $this->checkoutCommitmentQuery($serviceId)
            ->where('status', ResourceReservation::STATUS_PAID_COMMITTED)
            ->where('provisioning_lease_id', $leaseId)
            ->update([
                'provisioning_started_at' => null,
                'provisioning_lease_id' => null,
                'next_provisioning_attempt_at' => now()->addSeconds($delay),
                'last_provisioning_error' => Str::limit($exception->getMessage(), 1000, ''),
                'updated_at' => now(),
            ]);
    }

    /**
     * Persist a cancellation tombstone before any asynchronous delete runs.
     *
     * Returns true when the service has a dynamic reservation.
     */
    public function requestServiceCancellation(Service $service): bool
    {
        return DB::transaction(function () use ($service) {
            $lockedService = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $reservation = $this->checkoutCommitmentQuery($lockedService->id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return false;
            }

            if ($reservation->status === ResourceReservation::STATUS_PENDING) {
                DB::table('ptero_resource_reservations')
                    ->where('id', $reservation->id)
                    ->update([
                        'status' => ResourceReservation::STATUS_CANCELLED,
                        'cancellation_requested_at' => now(),
                        'provisioning_started_at' => null,
                        'provisioning_lease_id' => null,
                        'admin_notes' => 'Service cancelled before payment.',
                        'updated_at' => now(),
                    ]);
                $this->releaseAllocationClaims((int) $reservation->id);
                $this->releaseProductStockOnce($reservation, $lockedService);
                $lockedService->status = Service::STATUS_CANCELLED;
                $this->persistFulfillmentService($lockedService);

                return true;
            }

            if ($reservation->status === ResourceReservation::STATUS_CANCELLED) {
                return true;
            }

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'cancellation_requested_at' => $reservation->cancellation_requested_at ?? now(),
                    'updated_at' => now(),
                ]);
            $lockedService->status = 'cancellation_pending';
            $this->persistFulfillmentService($lockedService);

            return true;
        }, 5);
    }

    /**
     * Return the integrity-checked checkout contract used to reconcile a
     * cancellation after an external create may have succeeded without
     * returning a response to Paymenter.
     *
     * This path is intentionally limited to an unconsumed paid commitment
     * with no pinned external identity. It never creates or adopts a
     * Pterodactyl customer.
     *
     * @return array<string, mixed>|null
     */
    public function cancellationReconciliationContext(
        int|Service $service
    ): ?array {
        $serviceId = $service instanceof Service
            ? (int) $service->id
            : $service;

        return DB::transaction(function () use ($serviceId) {
            $lockedService = Service::query()
                ->whereKey($serviceId)
                ->lockForUpdate()
                ->firstOrFail();
            $reservation = $this->checkoutCommitmentQuery($serviceId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return null;
            }

            return $this->buildCancellationReconciliationContext(
                $lockedService,
                $reservation,
                true
            );
        }, 5);
    }

    /**
     * Persist the exact external identity only after the provisioner has
     * matched the candidate server to cancellationReconciliationContext().
     * Re-validate the complete signed checkout contract under the same row
     * lock so a stale or competing cancellation worker cannot pin a different
     * server.
     *
     * @param  array<string, mixed>  $externalServer
     * @return array<string, mixed>
     */
    public function pinCancellationServerIdentity(
        int|Service $service,
        array $externalServer,
        int $expectedExternalUserId
    ): array {
        $serviceId = $service instanceof Service
            ? (int) $service->id
            : $service;

        return DB::transaction(function () use (
            $serviceId,
            $externalServer,
            $expectedExternalUserId
        ) {
            $lockedService = Service::query()
                ->whereKey($serviceId)
                ->lockForUpdate()
                ->firstOrFail();
            $reservation = $this->checkoutCommitmentQuery($serviceId)
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw new PermanentProvisioningException(
                    'The cancellation has no checkout commitment to reconcile.'
                );
            }

            $context = $this->buildCancellationReconciliationContext(
                $lockedService,
                $reservation,
                false
            );
            if ($context['provisioning_in_flight']) {
                throw new \RuntimeException(
                    'Provisioning is still in flight; cancellation will retry.'
                );
            }

            $this->assertCancellationServerMatchesContext(
                $externalServer,
                $context,
                $serviceId,
                $expectedExternalUserId
            );
            $attributes = $externalServer['attributes'] ?? $externalServer;
            $externalIdentity = $this->externalServerIdentity($attributes);
            $storedIdentity = [
                'external_server_id' => $reservation->external_server_id,
                'external_user_id' => $reservation->external_user_id,
                'external_server_uuid' => $reservation->external_server_uuid,
                'external_server_identifier' => $reservation->external_server_identifier,
            ];
            $hasStoredIdentity = collect($storedIdentity)
                ->contains(fn ($value): bool => $value !== null);

            if ($hasStoredIdentity) {
                $storedIdentityMatches =
                    is_numeric($storedIdentity['external_server_id'])
                    && (int) $storedIdentity['external_server_id']
                        === $externalIdentity['external_server_id']
                    && is_numeric($storedIdentity['external_user_id'])
                    && (int) $storedIdentity['external_user_id']
                        === $externalIdentity['external_user_id']
                    && is_string(
                        $storedIdentity['external_server_uuid']
                    )
                    && hash_equals(
                        $storedIdentity['external_server_uuid'],
                        $externalIdentity['external_server_uuid']
                    )
                    && is_string(
                        $storedIdentity['external_server_identifier']
                    )
                    && hash_equals(
                        $storedIdentity['external_server_identifier'],
                        $externalIdentity[
                            'external_server_identifier'
                        ]
                    );
                if (! $storedIdentityMatches) {
                    throw new PermanentProvisioningException(
                        'The cancellation found a conflicting pinned Pterodactyl server identity.'
                    );
                }
            } else {
                DB::table('ptero_resource_reservations')
                    ->where('id', $reservation->id)
                    ->update([
                        ...$externalIdentity,
                        'last_reconciled_at' => now(),
                        'provisioning_started_at' => null,
                        'provisioning_lease_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            return [
                ...$context,
                ...$externalIdentity,
            ];
        }, 5);
    }

    /**
     * Finish cancellation only after the provisioner has established that the
     * external server is absent.
     */
    public function completeServiceCancellation(int|Service $service): bool
    {
        $serviceId = $service instanceof Service ? (int) $service->id : $service;

        return DB::transaction(function () use ($serviceId) {
            $lockedService = Service::query()->whereKey($serviceId)->lockForUpdate()->firstOrFail();
            $reservation = $this->checkoutCommitmentQuery($serviceId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                $lockedService->status = Service::STATUS_CANCELLED;
                $this->persistFulfillmentService($lockedService);

                return false;
            }

            if (
                $reservation->provisioning_started_at !== null
                && Carbon::parse($reservation->provisioning_started_at)
                    ->greaterThan(now()->subMinutes(self::PROVISIONING_LEASE_MINUTES))
            ) {
                throw new \RuntimeException('Provisioning is still in flight; cancellation will retry.');
            }

            if ($reservation->status !== ResourceReservation::STATUS_CONFIRMED) {
                DB::table('ptero_resource_reservations')
                    ->where('id', $reservation->id)
                    ->update([
                        'status' => ResourceReservation::STATUS_CANCELLED,
                        'cancellation_requested_at' => $reservation->cancellation_requested_at ?? now(),
                        'provisioning_started_at' => null,
                        'provisioning_lease_id' => null,
                        'next_provisioning_attempt_at' => null,
                        'updated_at' => now(),
                    ]);
                $this->releaseAllocationClaims((int) $reservation->id);
            }

            $this->releaseProductStockOnce($reservation, $lockedService);
            $lockedService->status = Service::STATUS_CANCELLED;
            $this->persistFulfillmentService($lockedService);

            return true;
        }, 5);
    }

    public function provisioningMayContinue(int $serviceId, ?string $leaseId): bool
    {
        if ($leaseId === null) {
            return false;
        }

        return $this->checkoutCommitmentQuery($serviceId)
            ->where('status', ResourceReservation::STATUS_PAID_COMMITTED)
            ->where('provisioning_lease_id', $leaseId)
            ->whereNull('cancellation_requested_at')
            ->exists();
    }

    /**
     * Reservation history is deliberately permanent. Administrative and API
     * entry points use this check to prevent a local hard-delete or raw
     * extension action from bypassing fulfillment and orphaning a server.
     */
    public function hasCheckoutReservation(int $serviceId): bool
    {
        return DB::table('ptero_resource_reservations')
            ->where('purpose', 'checkout')
            ->where('service_id', $serviceId)
            ->exists();
    }

    /**
     * Return the immutable identity that every post-provisioning lifecycle
     * action must prove before it controls an external server.
     *
     * @return array<string, mixed>|null
     */
    public function serverLifecycleIdentity(int|Service $service): ?array
    {
        $serviceId = $service instanceof Service
            ? (int) $service->id
            : $service;
        $reservation = $this->checkoutCommitmentQuery($serviceId)->first();
        if ($reservation === null) {
            return null;
        }

        try {
            $payload = json_decode(
                (string) $reservation->configuration_payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new PermanentProvisioningException(
                'The durable server identity snapshot is unreadable.',
                previous: $exception
            );
        }
        if (
            ! is_array($payload)
            || ! is_string($reservation->configuration_fingerprint)
            || ! hash_equals(
                $reservation->configuration_fingerprint,
                $this->configurationService->fingerprint($payload)
            )
        ) {
            throw new PermanentProvisioningException(
                'The durable server identity snapshot failed its integrity check.'
            );
        }

        $provisioning = (array) (
            $payload['provisioning_identity'] ?? []
        );

        return [
            'reservation_id' => (int) $reservation->id,
            'status' => (string) $reservation->status,
            'panel_identity' => (string) $reservation->panel_identity,
            'node_id' => (int) $reservation->node_id,
            'external_server_id' => $reservation->external_server_id !== null
                ? (int) $reservation->external_server_id
                : null,
            'external_user_id' => $reservation->external_user_id !== null
                ? (int) $reservation->external_user_id
                : null,
            'external_server_uuid' => $reservation->external_server_uuid,
            'external_server_identifier' => $reservation->external_server_identifier,
            'external_server_external_id' => (string) $serviceId,
            'user_external_id' => $provisioning['user_external_id'] ?? null,
            'user_email' => $provisioning['user_email'] ?? null,
            'nest_id' => $provisioning['nest_id'] ?? null,
            'egg_id' => $provisioning['egg_id'] ?? null,
        ];
    }

    /**
     * Called by CreateJob::failed(). The paid commitment remains reserved.
     *
     * @return array<string, mixed>|null
     */
    public function recordPermanentProvisioningFailure(Service $service, \Throwable $exception): ?array
    {
        return DB::transaction(function () use ($service, $exception) {
            $lockedService = Service::query()
                ->whereKey($service->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reservation = $this->checkoutCommitmentQuery($lockedService->id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null || $reservation->status !== ResourceReservation::STATUS_PAID_COMMITTED) {
                return null;
            }

            $permanent = $exception instanceof PermanentProvisioningException;
            $snapshot = [
                'permanent' => $permanent,
                'reservation_id' => (int) $reservation->id,
                'service_id' => (int) $lockedService->id,
                'invoice_id' => $reservation->invoice_id !== null ? (int) $reservation->invoice_id : null,
                'node_id' => (int) $reservation->node_id,
                'memory' => (int) $reservation->memory,
                'cpu' => (int) $reservation->cpu,
                'disk' => (int) $reservation->disk,
                'attempts' => (int) $reservation->provisioning_attempts,
                'error' => Str::limit($exception->getMessage(), 1000, ''),
            ];

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'provisioning_started_at' => null,
                    'provisioning_lease_id' => null,
                    'last_provisioning_error' => $snapshot['error'],
                    'next_provisioning_attempt_at' => $permanent ? null : now()->addHour(),
                    'failure_alerted_at' => $reservation->failure_alerted_at ?? now(),
                    'updated_at' => now(),
                ]);

            if ($permanent) {
                $lockedService->status = Service::STATUS_PROVISIONING_FAILED;
                $this->persistFulfillmentService($lockedService);
            }

            if ($reservation->failure_alerted_at === null) {
                app(AlertService::class)->notifyProvisioningFailure($snapshot);
            }

            return $snapshot;
        }, 5);
    }

    /**
     * Called by TerminateJob::failed(). The cancellation tombstone remains in
     * place so neither a retrying create job nor a duplicate payment can expose
     * the server.
     *
     * @return array<string, mixed>|null
     */
    public function recordPermanentCancellationFailure(
        Service $service,
        \Throwable $exception
    ): ?array {
        return DB::transaction(function () use ($service, $exception) {
            $reservation = $this->checkoutCommitmentQuery((int) $service->id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null || $reservation->cancellation_requested_at === null) {
                return null;
            }

            $snapshot = [
                'operation' => 'cancellation',
                'reservation_id' => (int) $reservation->id,
                'service_id' => (int) $service->id,
                'invoice_id' => $reservation->invoice_id !== null ? (int) $reservation->invoice_id : null,
                'node_id' => (int) $reservation->node_id,
                'attempts' => 8,
                'error' => Str::limit($exception->getMessage(), 1000, ''),
            ];

            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'last_cancellation_error' => $snapshot['error'],
                    'cancellation_failure_alerted_at' => $reservation->cancellation_failure_alerted_at ?? now(),
                    'updated_at' => now(),
                ]);

            if ($reservation->cancellation_failure_alerted_at === null) {
                app(AlertService::class)->notifyProvisioningFailure($snapshot);
            }

            return $snapshot;
        }, 5);
    }

    public function markCustomerNotified(int $serviceId): ?bool
    {
        if (! $this->checkoutCommitmentQuery($serviceId)->exists()) {
            return null;
        }

        return $this->checkoutCommitmentQuery($serviceId)
            ->whereNull('customer_notified_at')
            ->update([
                'customer_notified_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    public function customerNotificationPending(int $serviceId): ?bool
    {
        $reservation = $this->checkoutCommitmentQuery($serviceId)
            ->first(['customer_notified_at']);

        return $reservation === null ? null : $reservation->customer_notified_at === null;
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

            $updated = DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => 'cancelled',
                    'admin_notes' => 'Cart item removed.',
                    'updated_at' => now(),
                ]) > 0;
            if ($updated) {
                $this->releaseAllocationClaims((int) $reservation->id);
            }

            return $updated;
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
        $reservationModel = ResourceReservation::query()
            ->where('token', $token)
            ->first();
        if ($reservationModel === null) {
            return false;
        }

        if ($actor !== null) {
            Gate::forUser($actor)->authorize('cancel', $reservationModel);
        }

        $reservation = DB::transaction(function () use (
            $token,
            $reason
        ): ?object {
            $reservation = DB::table('ptero_resource_reservations')
                ->where('token', $token)
                ->lockForUpdate()
                ->first();
            if (
                $reservation === null
                || $reservation->status !== 'pending'
                || $reservation->service_id !== null
            ) {
                return null;
            }

            $updated = DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->where('status', 'pending')
                ->whereNull('service_id')
                ->update([
                    'status' => 'cancelled',
                    'admin_notes' => $reason,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException(
                    'The reservation changed while cancellation was being committed.'
                );
            }

            $this->releaseAllocationClaims((int) $reservation->id);

            return $reservation;
        }, 5);
        if ($reservation === null) {
            return false;
        }

        $this->safeAudit(
            'reservation_cancelled',
            'resource_reservation',
            $reservation->id,
            [
                'source' => $source,
                'node_id' => $reservation->node_id,
            ]
        );

        return true;
    }

    /**
     * Admin-only TTL extension.
     */
    public function extend(string $token, int $additionalMinutes = 15, ?User $actor = null): bool
    {
        if (
            $additionalMinutes < 1
            || $additionalMinutes > self::MAX_ADMIN_EXTENSION_MINUTES
        ) {
            throw new \InvalidArgumentException(
                'A reservation extension must be between 1 and 60 minutes.'
            );
        }

        $extended = DB::transaction(function () use (
            $token,
            $additionalMinutes,
            $actor
        ): ?array {
            $reservation = ResourceReservation::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                return null;
            }

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('extend', $reservation);
            }

            if (
                $reservation->status !== ResourceReservation::STATUS_PENDING
                || $reservation->service_id !== null
                || $reservation->invoice_id !== null
            ) {
                return null;
            }

            $now = now();
            $currentExpiresAt = $reservation->expires_at?->copy();
            $maximumExpiresAt = $now->copy()->addMinutes(
                self::MAX_ADMIN_EXTENSION_MINUTES
            );
            if (
                $currentExpiresAt === null
                || ! $currentExpiresAt->greaterThan($now)
                || ! $currentExpiresAt->lessThan($maximumExpiresAt)
            ) {
                return null;
            }

            $requestedExpiresAt = $currentExpiresAt
                ->copy()
                ->addMinutes($additionalMinutes);
            $newExpiresAt = $requestedExpiresAt->greaterThan(
                $maximumExpiresAt
            )
                ? $maximumExpiresAt
                : $requestedExpiresAt;
            $updated = DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->where(
                    'status',
                    ResourceReservation::STATUS_PENDING
                )
                ->whereNull('service_id')
                ->whereNull('invoice_id')
                ->update([
                    'expires_at' => $newExpiresAt,
                    'guaranteed_until' => $newExpiresAt,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException(
                    'The reservation changed while its extension was being committed.'
                );
            }

            return [
                'id' => (int) $reservation->id,
                'node_id' => (int) $reservation->node_id,
                'previous_expires_at' => $currentExpiresAt->toIso8601String(),
                'expires_at' => $newExpiresAt->toIso8601String(),
                'capped' => $requestedExpiresAt->greaterThan(
                    $maximumExpiresAt
                ),
            ];
        }, 5);
        if ($extended === null) {
            return false;
        }

        $this->safeAudit(
            'reservation_extended',
            'resource_reservation',
            $extended['id'],
            [
                'additional_minutes' => $additionalMinutes,
                'previous_expires_at' => $extended['previous_expires_at'],
                'expires_at' => $extended['expires_at'],
                'capped' => $extended['capped'],
                'node_id' => $extended['node_id'],
            ]
        );

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

        $revenueRows = DB::table('ptero_resource_reservations')
            ->where('created_at', '>=', $startDate)
            ->where('status', 'confirmed')
            ->selectRaw('currency_code, SUM(calculated_price) as total')
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();
        $revenueCents = [];
        foreach ($revenueRows as $row) {
            $currency = strtoupper(trim((string) $row->currency_code));
            $cents = $this->moneyCents($row->total);
            if ($cents === null) {
                throw new \RuntimeException(
                    'Confirmed reservation revenue contains an invalid amount.'
                );
            }
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                $currency = 'UNSPECIFIED';
            }
            $current = $revenueCents[$currency] ?? 0;
            if ($current > PHP_INT_MAX - $cents) {
                throw new \RuntimeException(
                    'Confirmed reservation revenue exceeds the supported range.'
                );
            }
            $revenueCents[$currency] = $current + $cents;
        }
        ksort($revenueCents);
        $revenueByCurrency = array_map(
            fn (int $cents): string => $this->moneyFromCents($cents),
            $revenueCents
        );

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
            'confirmed_revenue_by_currency' => $revenueByCurrency,
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

    public function cleanupExpired(int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));
        $count = app(SchedulerHealthService::class)->processEligibleRows(
            SchedulerHealthService::TASK_EXPIRE_CHECKOUT,
            'resource_reservation',
            $limit,
            fn (): Builder => DB::table('ptero_resource_reservations')
                ->where('purpose', 'checkout')
                ->where('status', 'pending')
                ->whereRaw(
                    'COALESCE(guaranteed_until, expires_at) <= ?',
                    [now()]
                ),
            function (int $candidateId): bool {
                return DB::transaction(function () use ($candidateId) {
                    $candidate = DB::table('ptero_resource_reservations')->where('id', $candidateId)->first();
                    if ($candidate === null) {
                        return false;
                    }

                    $invoice = $candidate->invoice_id !== null
                        ? Invoice::query()->whereKey($candidate->invoice_id)->lockForUpdate()->first()
                        : null;
                    $itemSnapshotIds = $invoice !== null
                        ? $invoice->items()->orderBy('id')->pluck('id')
                        : collect();
                    $serviceIds = $invoice !== null
                        ? $invoice->items()
                            ->where('reference_type', Service::class)
                            ->pluck('reference_id')
                            ->merge(
                                DB::table('ptero_resource_reservations')
                                    ->where('purpose', 'checkout')
                                    ->where('invoice_id', $invoice->id)
                                    ->pluck('service_id')
                            )
                            ->filter()
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->sort()
                            ->values()
                        : collect(array_filter([(int) ($candidate->service_id ?? 0)]));
                    $services = Service::query()
                        ->whereKey($serviceIds->all())
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $service = $services->firstWhere('id', (int) $candidate->service_id);
                    $reservations = DB::table('ptero_resource_reservations')
                        ->where('purpose', 'checkout')
                        ->when(
                            $invoice !== null,
                            fn (Builder $query) => $query->where(
                                'invoice_id',
                                $invoice->id
                            ),
                            fn (Builder $query) => $query->where('id', $candidateId)
                        )
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $reservation = $reservations->firstWhere('id', $candidateId);
                    $lockedItems = $invoice !== null
                        ? $invoice->items()
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get()
                        : collect();
                    if (
                        $invoice !== null
                        && $lockedItems->pluck('id')->all()
                            !== $itemSnapshotIds->all()
                    ) {
                        throw new \RuntimeException(
                            'The capacity invoice obligations changed while expiry was acquiring its locks.'
                        );
                    }

                    if (
                        $reservation === null
                        || $reservation->status !== ResourceReservation::STATUS_PENDING
                        || Carbon::parse(
                            $reservation->guaranteed_until ?? $reservation->expires_at
                        )->isFuture()
                    ) {
                        return false;
                    }

                    if ($invoice?->status === Invoice::STATUS_PAID && $service !== null) {
                        $reason = 'A paid invoice was found after its capacity guarantee expired before fulfillment was committed.';
                        $snapshot = [
                            'reservation_id' => (int) $reservation->id,
                            'service_id' => (int) $service->id,
                            'invoice_id' => (int) $invoice->id,
                            'node_id' => (int) $reservation->node_id,
                            'memory' => (int) $reservation->memory,
                            'cpu' => (int) $reservation->cpu,
                            'disk' => (int) $reservation->disk,
                            'attempts' => (int) $reservation->provisioning_attempts,
                            'error' => $reason,
                        ];
                        $expiringReservations = $reservations->where(
                            'status',
                            ResourceReservation::STATUS_PENDING
                        );
                        foreach ($expiringReservations as $expiringReservation) {
                            DB::table('ptero_resource_reservations')
                                ->where('id', $expiringReservation->id)
                                ->update([
                                    'status' => ResourceReservation::STATUS_EXPIRED,
                                    'last_provisioning_error' => $reason,
                                    'failure_alerted_at' => $expiringReservation->failure_alerted_at ?? now(),
                                    'admin_notes' => $reason,
                                    'updated_at' => now(),
                                ]);
                            $this->releaseAllocationClaims(
                                (int) $expiringReservation->id
                            );
                        }
                        foreach ($services as $linkedService) {
                            if ($linkedService->status !== Service::STATUS_PENDING) {
                                continue;
                            }
                            $linkedReservation = $reservations->firstWhere(
                                'service_id',
                                $linkedService->id
                            );
                            if ($linkedReservation !== null) {
                                $this->releaseProductStockOnce(
                                    $linkedReservation,
                                    $linkedService
                                );
                            } else {
                                app(ProductStockService::class)->release(
                                    $linkedService
                                );
                            }
                            $linkedService->status = Service::STATUS_PROVISIONING_FAILED;
                            $this->persistFulfillmentService($linkedService);
                        }

                        if ($reservation->failure_alerted_at === null) {
                            DB::afterCommit(function () use ($snapshot, $reason) {
                                $alerts = app(AlertService::class);
                                if (method_exists($alerts, 'notifyShortfall')) {
                                    $alerts->notifyShortfall(
                                        $snapshot['service_id'],
                                        $snapshot['invoice_id'],
                                        $snapshot,
                                        $reason
                                    );
                                } else {
                                    $alerts->notifyProvisioningFailure($snapshot);
                                }
                            });
                        }

                        return true;
                    }

                    if (
                        $invoice?->status === Invoice::STATUS_PAID
                        || $service?->status === 'provisioning'
                    ) {
                        throw new \RuntimeException(
                            'An expired hold cannot be reclaimed after payment or provisioning begins.'
                        );
                    }

                    $paymentAttention = $invoice !== null
                        && $invoice->status === Invoice::STATUS_PENDING
                        && app(CapacityInvoicePaymentService::class)
                            ->hasInFlightOrSucceededPayment($invoice);

                    foreach (
                        $reservations->where(
                            'status',
                            ResourceReservation::STATUS_PENDING
                        ) as $expiringReservation
                    ) {
                        DB::table('ptero_resource_reservations')
                            ->where('id', $expiringReservation->id)
                            ->update([
                                'status' => ResourceReservation::STATUS_EXPIRED,
                                'provisioning_started_at' => null,
                                'provisioning_lease_id' => null,
                                'updated_at' => now(),
                            ]);
                        $this->releaseAllocationClaims(
                            (int) $expiringReservation->id
                        );
                    }

                    if ($paymentAttention) {
                        app(CapacityInvoicePaymentService::class)->requireAttention(
                            $invoice,
                            'The seven-day capacity guarantee expired after a partial or in-flight payment. Capacity was released; refund or account-credit review is required.'
                        );
                    } elseif ($invoice !== null && $invoice->status === Invoice::STATUS_PENDING) {
                        app(CancelInvoiceService::class)
                            ->markCancelledAfterFulfillment($invoice);
                    }
                    foreach ($services as $linkedService) {
                        if ($linkedService->status !== Service::STATUS_PENDING) {
                            continue;
                        }

                        $linkedService->status = $paymentAttention
                            ? Service::STATUS_PROVISIONING_FAILED
                            : Service::STATUS_CANCELLED;
                        $this->persistFulfillmentService($linkedService);

                        $linkedReservation = $reservations->firstWhere(
                            'service_id',
                            $linkedService->id
                        );
                        if ($linkedReservation !== null) {
                            $this->releaseProductStockOnce(
                                $linkedReservation,
                                $linkedService
                            );
                        } else {
                            app(ProductStockService::class)->release($linkedService);
                        }
                    }

                    return true;
                }, 5);
            }
        );

        if ($count > 0) {
            $this->safeAudit('reservations_expired_batch', 'resource_reservation', 0, [
                'count' => $count,
                'run_at' => now()->toIso8601String(),
            ]);
        }

        return $count;
    }

    /**
     * Requeue durable paid commitments that are not currently leased.
     * The extension schedule can call this independently of queue retry state.
     */
    public function reconcileStalledPaidCommitments(int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));
        $cutoff = now()->subMinutes(self::PROVISIONING_LEASE_MINUTES);

        return app(SchedulerHealthService::class)->processEligibleRows(
            SchedulerHealthService::TASK_RECONCILE_CHECKOUT,
            'resource_reservation',
            $limit,
            fn (): Builder => DB::table('ptero_resource_reservations')
                ->where(
                    'status',
                    ResourceReservation::STATUS_PAID_COMMITTED
                )
                ->whereNull('cancellation_requested_at')
                ->where(function (Builder $query): void {
                    $query->whereNull('next_provisioning_attempt_at')
                        ->orWhere(
                            'next_provisioning_attempt_at',
                            '<=',
                            now()
                        );
                })
                ->where(function (Builder $query) use ($cutoff): void {
                    $query->whereNull('provisioning_started_at')
                        ->orWhere('provisioning_started_at', '<=', $cutoff);
                }),
            function (int $reservationId) use ($cutoff): bool {
                $service = DB::transaction(
                    function () use ($reservationId, $cutoff) {
                        $candidate = DB::table(
                            'ptero_resource_reservations'
                        )
                            ->where('id', $reservationId)
                            ->first(['service_id']);
                        if ($candidate?->service_id === null) {
                            return null;
                        }

                        $service = Service::query()
                            ->whereKey($candidate->service_id)
                            ->lockForUpdate()
                            ->first();
                        $reservation = DB::table(
                            'ptero_resource_reservations'
                        )
                            ->where('id', $reservationId)
                            ->lockForUpdate()
                            ->first();
                        if (
                            $service === null
                            || $service->status
                                !== Service::STATUS_PROVISIONING
                            || $reservation === null
                            || $reservation->status
                                !== ResourceReservation::STATUS_PAID_COMMITTED
                            || $reservation->cancellation_requested_at !== null
                            || (
                                $reservation->next_provisioning_attempt_at
                                    !== null
                                && Carbon::parse(
                                    $reservation
                                        ->next_provisioning_attempt_at
                                )->isFuture()
                            )
                            || (
                                $reservation->provisioning_started_at !== null
                                && Carbon::parse(
                                    $reservation->provisioning_started_at
                                )->greaterThan($cutoff)
                            )
                        ) {
                            return null;
                        }

                        DB::table('ptero_resource_reservations')
                            ->where('id', $reservationId)
                            ->update([
                                'provisioning_started_at' => null,
                                'provisioning_lease_id' => null,
                                'next_provisioning_attempt_at' => now()
                                    ->addMinutes(10),
                                'updated_at' => now(),
                            ]);

                        return $service;
                    },
                    5
                );

                if (
                    $service === null
                    || ! class_exists(ServiceJobDispatchService::class)
                ) {
                    return false;
                }

                app(ServiceJobDispatchService::class)
                    ->requestCreate($service);

                return true;
            }
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function matchesSnapshot(object $reservation, array $snapshot): bool
    {
        $storedPayload = json_decode(
            (string) $reservation->configuration_payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payload = $this->configurationService->withPlacement(
            $snapshot,
            (int) $reservation->node_id,
            (array) ($storedPayload['allocations'] ?? [])
        );

        return hash_equals(
            (string) $reservation->configuration_fingerprint,
            $this->configurationService->fingerprint($payload)
        );
    }

    /**
     * @return array{
     *     reservation_id: int,
     *     panel_identity: string,
     *     node_id: int,
     *     location_id: int,
     *     memory: int,
     *     cpu: int,
     *     disk: int,
     *     provisioning_lease_id: string|null,
     *     already_consumed: bool,
     *     allocations: array<int, array<string, mixed>>,
     *     nest_id: int,
     *     egg_id: int,
     *     user_external_id: string
     * }
     */
    private function provisioningContext(
        object $reservation,
        bool $alreadyConsumed,
        ?string $leaseId = null
    ): array {
        try {
            $payload = json_decode(
                (string) $reservation->configuration_payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new PermanentProvisioningException(
                'The capacity reservation provisioning identity is unreadable.',
                previous: $exception
            );
        }
        $identity = is_array($payload)
            ? (array) ($payload['provisioning_identity'] ?? [])
            : [];
        $nestId = StrictInteger::parse(
            $identity['nest_id'] ?? null
        );
        $eggId = StrictInteger::parse(
            $identity['egg_id'] ?? null
        );
        $userExternalId = $identity['user_external_id'] ?? null;
        if (
            $nestId === null
            || $nestId <= 0
            || $eggId === null
            || $eggId <= 0
            || ! is_string($userExternalId)
            || $userExternalId === ''
        ) {
            throw new PermanentProvisioningException(
                'The capacity reservation has no valid provisioning identity.'
            );
        }

        $allocations = DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservation->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn ($allocation) => [
                'allocation_id' => (int) $allocation->allocation_id,
                'ip' => (string) ($allocation->ip ?? ''),
                'port' => (int) $allocation->port,
                'environment_key' => $allocation->environment_key,
                'is_primary' => (bool) $allocation->is_primary,
            ])
            ->all();

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
            'allocations' => $allocations,
            'nest_id' => $nestId,
            'egg_id' => $eggId,
            'user_external_id' => $userExternalId,
        ];
    }

    /**
     * Apply the single authoritative predicate used by stock calculations.
     */
    public function applyCapacityHoldingScope(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where(
                'status',
                ResourceReservation::STATUS_PENDING
            )->orWhere('status', ResourceReservation::STATUS_PAID_COMMITTED);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $available
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array<int, array<string, mixed>>
     */
    private function mapAllocationRequirements(array $available, array $requirements): array
    {
        $pool = array_values($available);
        $mapped = [];
        $requirements = $requirements !== [] ? array_values($requirements) : [[
            'environment_key' => 'SERVER_PORT',
            'requested_port' => null,
            'is_primary' => true,
        ]];

        // Claim explicit ports first. Otherwise an earlier wildcard (commonly
        // SERVER_PORT) could consume an allocation needed by a later fixed
        // egg-variable mapping even though the node passed the quote check.
        $requirements = array_values(array_merge(
            array_filter(
                $requirements,
                fn (array $requirement): bool => ($requirement['requested_port'] ?? null) !== null
            ),
            array_filter(
                $requirements,
                fn (array $requirement): bool => ($requirement['requested_port'] ?? null) === null
            )
        ));

        foreach ($requirements as $requirement) {
            $requestedPort = $requirement['requested_port'] ?? null;
            $index = null;
            if ($requestedPort !== null) {
                foreach ($pool as $candidateIndex => $candidate) {
                    if ((int) ($candidate['port'] ?? 0) === (int) $requestedPort) {
                        $index = $candidateIndex;
                        break;
                    }
                }
                if ($index === null) {
                    throw new DisplayException(
                        "The selected node does not have requested port {$requestedPort} available."
                    );
                }
            }
            $index ??= array_key_first($pool);
            if ($index === null) {
                throw new DisplayException('The selected node no longer has enough free allocations.');
            }

            $allocation = $pool[$index];
            unset($pool[$index]);
            $pool = array_values($pool);
            $mapped[] = [
                'allocation_id' => (int) ($allocation['allocation_id'] ?? $allocation['id'] ?? 0),
                'ip' => (string) ($allocation['ip'] ?? ''),
                'port' => (int) ($allocation['port'] ?? 0),
                'environment_key' => (string) ($requirement['environment_key'] ?? 'SERVER_PORT'),
                'is_primary' => (bool) ($requirement['is_primary'] ?? false),
            ];
        }

        if (! collect($mapped)->contains('is_primary', true)) {
            $mapped[0]['is_primary'] = true;
        }

        foreach ($mapped as $allocation) {
            if ($allocation['allocation_id'] <= 0 || $allocation['port'] <= 0) {
                throw new DisplayException('Pterodactyl returned an invalid allocation.');
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     external_server_id: int,
     *     external_user_id: int,
     *     external_server_uuid: string,
     *     external_server_identifier: string
     * }
     */
    private function externalServerIdentity(array $attributes): array
    {
        $id = $attributes['id'] ?? null;
        $userId = $attributes['user'] ?? null;
        $uuid = $attributes['uuid'] ?? null;
        $identifier = $attributes['identifier'] ?? null;

        if (
            ! is_numeric($id)
            || (int) $id <= 0
            || StrictInteger::parse($userId) === null
            || (int) $userId <= 0
            || ! is_string($uuid)
            || ! Str::isUuid($uuid)
            || ! is_string($identifier)
            || trim($identifier) === ''
        ) {
            throw new \RuntimeException(
                'Pterodactyl did not return a complete external server identity.'
            );
        }

        return [
            'external_server_id' => (int) $id,
            'external_user_id' => (int) $userId,
            'external_server_uuid' => $uuid,
            'external_server_identifier' => $identifier,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCancellationReconciliationContext(
        Service $service,
        object $reservation,
        bool $requireUnpinned
    ): array {
        if (
            $reservation->cancellation_requested_at === null
            || $service->status !== Service::STATUS_CANCELLATION_PENDING
        ) {
            throw new PermanentProvisioningException(
                'The service has no durable cancellation request to reconcile.'
            );
        }
        if (
            $reservation->status
                !== ResourceReservation::STATUS_PAID_COMMITTED
        ) {
            throw new PermanentProvisioningException(
                'Only an unconsumed paid commitment can use cancellation reconciliation.'
            );
        }

        $storedIdentity = [
            $reservation->external_server_id,
            $reservation->external_user_id,
            $reservation->external_server_uuid,
            $reservation->external_server_identifier,
        ];
        if (
            $requireUnpinned
            && collect($storedIdentity)
                ->contains(fn ($value): bool => $value !== null)
        ) {
            throw new PermanentProvisioningException(
                'The cancellation already has a pinned Pterodactyl server identity.'
            );
        }

        try {
            $this->configurationService->assertServiceMatches(
                $service,
                $reservation
            );
            $claims = DB::table('ptero_reservation_allocations')
                ->where('reservation_id', $reservation->id)
                ->lockForUpdate()
                ->get();
            $payload = $this->configurationService
                ->verifiedAllocationSnapshot($reservation, $claims);
        } catch (
            InvalidStockConfigurationException|\RuntimeException $exception
        ) {
            throw new PermanentProvisioningException(
                $exception->getMessage(),
                previous: $exception
            );
        }

        $identity = (array) ($payload['provisioning_identity'] ?? []);
        $nestId = StrictInteger::parse($identity['nest_id'] ?? null);
        $eggId = StrictInteger::parse($identity['egg_id'] ?? null);
        $userExternalId = $identity['user_external_id'] ?? null;
        if (
            $nestId === null
            || $nestId <= 0
            || $eggId === null
            || $eggId <= 0
            || ! is_string($userExternalId)
            || $userExternalId === ''
        ) {
            throw new PermanentProvisioningException(
                'The cancellation checkout contract has no valid customer or image identity.'
            );
        }

        $provisioningInFlight =
            $reservation->provisioning_started_at !== null
            && Carbon::parse($reservation->provisioning_started_at)
                ->greaterThan(
                    now()->subMinutes(self::PROVISIONING_LEASE_MINUTES)
                );

        return [
            'reservation_id' => (int) $reservation->id,
            'configuration_fingerprint' => (string) $reservation->configuration_fingerprint,
            'status' => (string) $reservation->status,
            'panel_identity' => (string) $reservation->panel_identity,
            'external_server_external_id' => (string) $service->id,
            'node_id' => (int) $reservation->node_id,
            'location_id' => (int) $reservation->location_id,
            'memory' => (int) $reservation->memory,
            'cpu' => (int) $reservation->cpu,
            'disk' => (int) $reservation->disk,
            'allocations' => array_values(
                (array) ($payload['allocations'] ?? [])
            ),
            'client_allocation_limit' => 0,
            'nest_id' => $nestId,
            'egg_id' => $eggId,
            'user_external_id' => $userExternalId,
            'user_email' => $identity['user_email'] ?? null,
            'provisioning_in_flight' => $provisioningInFlight,
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $context
     */
    private function assertCancellationServerMatchesContext(
        array $server,
        array $context,
        int $serviceId,
        int $expectedExternalUserId
    ): void {
        $attributes = $server['attributes'] ?? $server;
        if (! is_array($attributes)) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned an invalid cancellation server response.'
            );
        }
        $externalIdentity = $this->externalServerIdentity($attributes);
        if (
            $expectedExternalUserId <= 0
            || $externalIdentity['external_user_id']
                !== $expectedExternalUserId
            || ! is_string($attributes['external_id'] ?? null)
            || ! hash_equals(
                (string) $serviceId,
                $attributes['external_id']
            )
        ) {
            throw new PermanentProvisioningException(
                'The cancellation candidate does not belong to the reserved Paymenter customer and service.'
            );
        }

        foreach ([
            'node' => $context['node_id'],
            'nest' => $context['nest_id'],
            'egg' => $context['egg_id'],
        ] as $field => $expected) {
            if (
                StrictInteger::parse($attributes[$field] ?? null) === null
                || (int) $attributes[$field] !== (int) $expected
            ) {
                throw new PermanentProvisioningException(
                    "The cancellation candidate does not match reserved {$field}."
                );
            }
        }

        $limits = (array) ($attributes['limits'] ?? []);
        foreach (['memory', 'cpu', 'disk'] as $resource) {
            if (
                StrictInteger::parse($limits[$resource] ?? null) === null
                || (int) $limits[$resource]
                    !== (int) $context[$resource]
            ) {
                throw new PermanentProvisioningException(
                    "The cancellation candidate does not match reserved {$resource}."
                );
            }
        }
        if (
            StrictInteger::parse(
                data_get($attributes, 'feature_limits.allocations')
            ) === null
            || (int) data_get(
                $attributes,
                'feature_limits.allocations'
            ) !== 0
        ) {
            throw new PermanentProvisioningException(
                'The cancellation candidate permits unreserved client allocation changes.'
            );
        }

        $assigned = data_get(
            $attributes,
            'relationships.allocations.data',
            data_get($server, 'relationships.allocations.data')
        );
        if (! is_array($assigned)) {
            throw new PermanentProvisioningException(
                'The cancellation candidate has no verifiable allocation set.'
            );
        }
        $assignedIds = [];
        foreach ($assigned as $allocation) {
            $allocationId = is_array($allocation)
                ? StrictInteger::parse(
                    data_get($allocation, 'attributes.id')
                        ?? ($allocation['id'] ?? null)
                )
                : null;
            if ($allocationId === null || $allocationId <= 0) {
                throw new PermanentProvisioningException(
                    'The cancellation candidate has an invalid assigned allocation.'
                );
            }
            $assignedIds[] = $allocationId;
        }
        sort($assignedIds);
        if (count(array_unique($assignedIds)) !== count($assignedIds)) {
            throw new PermanentProvisioningException(
                'The cancellation candidate has duplicate assigned allocations.'
            );
        }

        $reserved = collect($context['allocations'] ?? []);
        $reservedIds = $reserved
            ->map(fn (array $allocation): int => (int) ($allocation['allocation_id'] ?? 0))
            ->sort()
            ->values()
            ->all();
        $primaryIds = $reserved
            ->filter(fn (array $allocation): bool => (bool) ($allocation['is_primary'] ?? false))
            ->map(fn (array $allocation): int => (int) ($allocation['allocation_id'] ?? 0))
            ->values();
        if (
            $reservedIds === []
            || count(array_unique($reservedIds)) !== count($reservedIds)
            || $primaryIds->count() !== 1
            || StrictInteger::parse($attributes['allocation'] ?? null)
                !== (int) $primaryIds->first()
            || $assignedIds !== $reservedIds
        ) {
            throw new PermanentProvisioningException(
                'The cancellation candidate allocation set does not exactly match the reservation.'
            );
        }
    }

    /**
     * @param  array{panel_identity: string, location_id: int}  $scope
     */
    private function capacityScopeKey(array $scope): string
    {
        return $scope['panel_identity'].':'.$scope['location_id'];
    }

    private function checkoutCommitmentQuery(int $serviceId): Builder
    {
        return DB::table('ptero_resource_reservations')
            ->where('service_id', $serviceId)
            ->where('purpose', 'checkout')
            ->whereIn('status', [
                ResourceReservation::STATUS_PENDING,
                ResourceReservation::STATUS_PAID_COMMITTED,
                ResourceReservation::STATUS_CONFIRMED,
            ]);
    }

    private function assertInvoiceLineMatchesReservation(
        Service $service,
        Invoice $invoice,
        object $reservation
    ): void {
        $lockedInvoice = Invoice::query()
            ->whereKey($invoice->id)
            ->lockForUpdate()
            ->firstOrFail();
        $lines = $lockedInvoice->items()
            ->where('reference_type', Service::class)
            ->where('reference_id', $service->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lines->count() !== 1) {
            throw new \RuntimeException(
                'A dynamic capacity commitment requires exactly one immutable invoice line for its service.'
            );
        }

        $line = $lines->first();
        $lineQuantity = (int) $line->quantity;
        $lineUnitCents = $this->moneyCents($line->price);
        $reservedAmountCents = $this->moneyCents(
            $reservation->calculated_price
        );
        $lineAmountCents = $lineUnitCents !== null
            && $lineQuantity > 0
            && $lineUnitCents <= intdiv(PHP_INT_MAX, $lineQuantity)
                ? $lineUnitCents * $lineQuantity
                : null;

        if (
            $lineAmountCents === null
            || $reservedAmountCents === null
            || $lineQuantity !== (int) $reservation->quantity
            || strtoupper((string) $lockedInvoice->currency_code)
                !== strtoupper((string) $reservation->currency_code)
            || $lineAmountCents !== $reservedAmountCents
        ) {
            throw new \RuntimeException(
                'The invoice line no longer matches the reserved quantity, currency, or pre-tax checkout price.'
            );
        }
    }

    private function moneyCents(mixed $value): ?int
    {
        if (is_float($value)) {
            if (
                ! is_finite($value)
                || $value < 0
                || abs($value - round($value, 2)) > 1e-8
            ) {
                return null;
            }
            $value = number_format($value, 2, '.', '');
        }

        $text = is_int($value)
            ? (string) $value
            : (is_string($value) ? $value : '');
        if (
            preg_match('/^(0|[1-9]\d*)(?:\.(\d+))?$/D', $text, $matches)
                !== 1
        ) {
            return null;
        }

        $whole = StrictInteger::parse($matches[1]);
        $fraction = $matches[2] ?? '';
        if (
            $whole === null
            || (strlen($fraction) > 2
                && trim(substr($fraction, 2), '0') !== '')
            || $whole > intdiv(PHP_INT_MAX - 99, 100)
        ) {
            return null;
        }

        return $whole * 100
            + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function moneyFromCents(int $cents): string
    {
        if ($cents < 0) {
            throw new \RuntimeException(
                'Reservation revenue cannot be negative.'
            );
        }

        return intdiv($cents, 100)
            .'.'
            .str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Prove that the allocation claim rows are an exact materialization of the
     * fingerprinted payload before any external request can use them.
     */
    private function assertAllocationClaimsMatch(object $reservation): void
    {
        $claims = DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservation->id)
            ->lockForUpdate()
            ->get();

        try {
            $this->configurationService->verifiedAllocationSnapshot(
                $reservation,
                $claims
            );
        } catch (InvalidStockConfigurationException $exception) {
            throw new PermanentProvisioningException(
                $exception->getMessage(),
                previous: $exception
            );
        }
    }

    private function persistFulfillmentService(Service $service): void
    {
        FulfillmentStatusTransitionService::run(
            $service,
            fn () => $service->save()
        );
    }

    protected function releaseAllocationClaims(int $reservationId): void
    {
        DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservationId)
            ->whereNull('released_at')
            ->update([
                'released_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Return Paymenter's product-level unit exactly once.
     *
     * Pending orders are released when their hold is cancelled or expires.
     * Paid/confirmed services reach this method only after the provisioner has
     * proved that the external server is absent.
     */
    private function releaseProductStockOnce(
        object $reservation,
        Service $service
    ): bool {
        $claimed = DB::table('ptero_resource_reservations')
            ->where('id', $reservation->id)
            ->whereNull('product_stock_released_at')
            ->update([
                'product_stock_released_at' => now(),
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return false;
        }

        app(ProductStockService::class)->release($service);

        return true;
    }
}
