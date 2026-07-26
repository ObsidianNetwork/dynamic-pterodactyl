<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\CartItem;
use App\Models\ConfigOption;
use App\Models\Extension;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\PanelEndpointIdentity;
use App\Support\StrictInteger;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidResourceSelectionException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;

class ReservationConfigurationService
{
    public const FORMULA_VERSION = 'dynamic-pterodactyl-v1';

    /**
     * Build the server-owned checkout snapshot before a node is selected.
     *
     * @return array{
     *     customer_id: int|null,
     *     cart_id: int,
     *     server_extension_id: int,
     *     panel_identity: string,
     *     product_id: int,
     *     plan_id: int,
     *     quantity: int,
     *     currency_code: string,
     *     location_id: int,
     *     resources: array{memory: int, cpu: int, disk: int},
     *     calculated_price: string,
     *     pricing_version: string,
     *     formula_version: string,
     *     config_options: array<int, array<string, mixed>>,
     *     allocation_requirements: array{
     *         required_count: int,
     *         mappings: array<int, array<string, mixed>>,
     *         allowed_port_ranges: array<int, array{from: int, to: int}>,
     *         dedicated_ip: bool
     *     }
     * }
     */
    public function forCartItem(CartItem $cartItem): array
    {
        $cartItem->loadMissing([
            'cart',
            'product.configOptions.children',
            'product.server.settings',
            'plan',
        ]);

        if (! $this->requiresReservation($cartItem->product_id)) {
            throw new \LogicException('The cart item does not use dynamic resource sliders.');
        }

        if ((int) $cartItem->quantity !== 1) {
            throw new DisplayException('Dynamic resource products currently require a quantity of one.');
        }

        try {
            $stockConfiguration = app(ProductResourceConfigurationService::class)
                ->forQuote($cartItem->product, (array) ($cartItem->config_options ?? []));
        } catch (InvalidResourceSelectionException|InvalidStockConfigurationException $exception) {
            throw new DisplayException($exception->getMessage(), previous: $exception);
        }
        $this->assertExplicitResourceSelections(
            (array) ($cartItem->config_options ?? []),
            (array) ($stockConfiguration['sliders'] ?? [])
        );

        $selectedOptions = collect($cartItem->config_options ?? [])
            ->keyBy(fn ($option) => (int) data_get($option, 'option_id'));

        $resources = $stockConfiguration['resources'];
        $locationId = (int) $stockConfiguration['location_id'];
        $configurationOptions = [];

        foreach ($cartItem->product->configOptions as $option) {
            $selection = $selectedOptions->get((int) $option->id);
            $value = data_get($selection, 'value');
            $resourceType = strtolower((string) $option->getMetadata('resource_type', ''));
            $environmentKey = strtolower((string) ($option->env_variable ?: $option->name));

            if ($option->type === 'dynamic_slider' && in_array($resourceType, ['memory', 'cpu', 'disk'], true)) {
                $value = $resources[$resourceType];
            }

            if ($environmentKey === 'location' || strtolower($option->name) === 'location') {
                $value = (int) $value;
            }

            $configurationOptions[] = [
                'id' => (int) $option->id,
                'type' => (string) $option->type,
                'environment_key' => $environmentKey,
                'resource_type' => $resourceType ?: null,
                'value' => is_numeric($value) ? (float) $value : $value,
                'metadata' => $this->canonicalize((array) ($option->metadata ?? [])),
            ];
        }

        usort($configurationOptions, fn (array $left, array $right) => $left['id'] <=> $right['id']);

        $calculatedPrice = $this->canonicalMoney(
            $cartItem->price->total,
            (int) $cartItem->quantity
        );
        $currencyCode = strtoupper((string) $cartItem->cart->currency_code);
        $panel = $this->checkoutPanelIdentity($cartItem);
        $allocationRequirements = [
            'required_count' => (int) $stockConfiguration['allocation_count'],
            'mappings' => (array) $stockConfiguration['allocation_mappings'],
            'allowed_port_ranges' => (array) (
                $stockConfiguration['allowed_port_ranges'] ?? []
            ),
            'dedicated_ip' => (bool) (
                $stockConfiguration['dedicated_ip'] ?? false
            ),
        ];
        $pricingIdentity = [
            'product_id' => (int) $cartItem->product_id,
            'plan_id' => (int) $cartItem->plan_id,
            'currency_code' => $currencyCode,
            'calculated_price' => $calculatedPrice,
            'config_options' => $configurationOptions,
        ];
        $customerId = $cartItem->cart->user_id !== null
            ? (int) $cartItem->cart->user_id
            : (auth()->id() !== null ? (int) auth()->id() : null);
        $provisioningIdentity = $this->provisioningIdentity(
            $cartItem->product,
            $customerId
        );

        return [
            'customer_id' => $customerId,
            'cart_id' => (int) $cartItem->cart_id,
            'server_extension_id' => $panel['server_extension_id'],
            'panel_identity' => $panel['panel_identity'],
            'product_id' => (int) $cartItem->product_id,
            'plan_id' => (int) $cartItem->plan_id,
            'quantity' => (int) $cartItem->quantity,
            'currency_code' => $currencyCode,
            'location_id' => $locationId,
            'resources' => $resources,
            'calculated_price' => $calculatedPrice,
            'pricing_version' => hash('sha256', $this->canonicalJson($pricingIdentity)),
            'formula_version' => self::FORMULA_VERSION,
            'config_options' => $configurationOptions,
            'allocation_requirements' => $allocationRequirements,
            'provisioning_identity' => $provisioningIdentity,
        ];
    }

    /**
     * Quotes may use configured defaults for their first render, but a stored
     * cart item is a billing input and must explicitly carry every active
     * resource slider. Otherwise a slider attached after the checkout page was
     * opened could default into the reservation while CartItem pricing omits
     * its marginal charge.
     *
     * @param  array<int|string, mixed>  $submittedOptions
     * @param  array<string, array<string, mixed>>  $sliders
     */
    private function assertExplicitResourceSelections(
        array $submittedOptions,
        array $sliders
    ): void {
        $submittedIds = [];
        if (array_is_list($submittedOptions)) {
            foreach ($submittedOptions as $selection) {
                $optionId = StrictInteger::parse(
                    data_get($selection, 'option_id')
                );
                if ($optionId !== null) {
                    $submittedIds[$optionId] = true;
                }
            }
        } else {
            foreach (array_keys($submittedOptions) as $optionId) {
                $optionId = StrictInteger::parse($optionId);
                if ($optionId !== null) {
                    $submittedIds[$optionId] = true;
                }
            }
        }

        foreach ($sliders as $slider) {
            $optionId = StrictInteger::parse(
                $slider['config_option_id'] ?? null
            );
            if ($optionId !== null && isset($submittedIds[$optionId])) {
                continue;
            }

            throw new DisplayException(
                'The product resource options changed before reservation. '
                .'Reload checkout and explicitly select every resource again.'
            );
        }
    }

    public function requiresReservation(int $productId): bool
    {
        $usesPterodactyl = Product::query()
            ->whereKey($productId)
            ->whereHas('server', fn ($query) => $query->where('extension', 'Pterodactyl'))
            ->exists();

        if (! $usesPterodactyl) {
            return false;
        }

        return ConfigOption::query()
            ->whereHas('products', fn ($query) => $query->whereKey($productId))
            ->where('type', 'dynamic_slider')
            ->where('hidden', false)
            ->whereNull('parent_id')
            ->get()
            ->contains(fn (ConfigOption $option) => in_array(
                strtolower((string) $option->getMetadata('resource_type', '')),
                ['memory', 'cpu', 'disk'],
                true
            ));
    }

    /**
     * A seven-day capacity claim is truthful only when every server create,
     * move, resize, and allocation assignment on eligible nodes participates
     * in this protocol.
     */
    public function assertExclusiveProvisioningControl(): void
    {
        try {
            app(PterodactylInventoryService::class)
                ->assertExclusiveProvisioningControl();
        } catch (\RuntimeException $exception) {
            throw new DisplayException(
                'Dynamic ordering is disabled until an administrator confirms that this Paymenter instance exclusively controls all provisioning on eligible Pterodactyl nodes.',
                previous: $exception
            );
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function withNode(array $snapshot, int $nodeId): array
    {
        return $this->canonicalize([
            'customer_id' => $snapshot['customer_id'],
            'cart_id' => $snapshot['cart_id'],
            'server_extension_id' => $snapshot['server_extension_id'],
            'panel_identity' => $snapshot['panel_identity'],
            'product_id' => $snapshot['product_id'],
            'plan_id' => $snapshot['plan_id'],
            'quantity' => $snapshot['quantity'],
            'currency_code' => $snapshot['currency_code'],
            'location_id' => $snapshot['location_id'],
            'node_id' => $nodeId,
            'resources' => $snapshot['resources'],
            'calculated_price' => $snapshot['calculated_price'],
            'pricing_version' => $snapshot['pricing_version'],
            'formula_version' => $snapshot['formula_version'],
            'config_options' => $snapshot['config_options'],
            'allocation_requirements' => $snapshot['allocation_requirements'],
            'provisioning_identity' => $snapshot['provisioning_identity'],
        ]);
    }

    /**
     * Add the exact Pterodactyl placement selected under the capacity lock.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<string, mixed>
     */
    public function withPlacement(array $snapshot, int $nodeId, array $allocations): array
    {
        $payload = $this->withNode($snapshot, $nodeId);
        $payload['allocations'] = array_values(array_map(
            fn (array $allocation) => [
                'allocation_id' => (int) ($allocation['allocation_id'] ?? $allocation['id'] ?? 0),
                'ip' => (string) ($allocation['ip'] ?? ''),
                'port' => (int) ($allocation['port'] ?? 0),
                'environment_key' => $allocation['environment_key'] ?? null,
                'is_primary' => (bool) ($allocation['is_primary'] ?? false),
            ],
            $allocations
        ));

        usort(
            $payload['allocations'],
            fn (array $left, array $right) => $left['allocation_id'] <=> $right['allocation_id']
        );

        return $this->canonicalize($payload);
    }

    /**
     * Transfer a guest payload to its authenticated customer without changing
     * any pricing, placement, or resource input.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withCustomer(
        array $payload,
        int $customerId,
        string $customerEmail
    ): array
    {
        $payload['customer_id'] = $customerId;
        $payload['provisioning_identity']['user_external_id']
            = $this->pterodactylUserExternalId($customerId);
        $payload['provisioning_identity']['user_email']
            = $this->normalizeCustomerEmail($customerEmail);

        return $this->canonicalize($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /**
     * Prove that the allocation claim rows are the exact materialization of a
     * signed checkout snapshot.
     *
     * Pending and paid commitments must still own active claims. Confirmed
     * commitments must retain the same historical rows after their claims are
     * released, so stock accounting can bridge stale Pterodactyl inventory
     * without trusting a detached or rewritten allocation row.
     *
     * @param  iterable<object>  $claims
     * @return array<string, mixed>
     */
    public function verifiedAllocationSnapshot(
        object $reservation,
        iterable $claims
    ): array {
        $payload = $reservation->configuration_payload;
        if (is_string($payload)) {
            try {
                $payload = json_decode(
                    $payload,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $exception) {
                throw new InvalidStockConfigurationException(
                    'The checkout allocation snapshot is unreadable.',
                    previous: $exception
                );
            }
        }

        if (
            ($reservation->purpose ?? null) !== 'checkout'
            || ! is_array($payload)
            || ! is_string($reservation->configuration_fingerprint)
            || ! hash_equals(
                $reservation->configuration_fingerprint,
                $this->fingerprint($payload)
            )
            || (string) ($payload['panel_identity'] ?? '')
                !== (string) $reservation->panel_identity
            || StrictInteger::parse($payload['node_id'] ?? null) === null
            || (int) $payload['node_id'] !== (int) $reservation->node_id
            || StrictInteger::parse(
                $payload['location_id'] ?? null
            ) === null
            || (int) $payload['location_id']
                !== (int) $reservation->location_id
            || StrictInteger::parse(
                data_get($payload, 'resources.memory')
            ) === null
            || (int) data_get($payload, 'resources.memory')
                !== (int) $reservation->memory
            || StrictInteger::parse(
                data_get($payload, 'resources.cpu')
            ) === null
            || (int) data_get($payload, 'resources.cpu')
                !== (int) $reservation->cpu
            || StrictInteger::parse(
                data_get($payload, 'resources.disk')
            ) === null
            || (int) data_get($payload, 'resources.disk')
                !== (int) $reservation->disk
        ) {
            throw new InvalidStockConfigurationException(
                'The checkout allocation snapshot failed its immutable capacity integrity check.'
            );
        }

        $rawExpected = $payload['allocations'] ?? null;
        $requiredCount = StrictInteger::parse(
            data_get($payload, 'allocation_requirements.required_count')
        );
        if (
            ! is_array($rawExpected)
            || $rawExpected === []
            || $requiredCount === null
            || $requiredCount <= 0
            || count($rawExpected) !== $requiredCount
        ) {
            throw new InvalidStockConfigurationException(
                'The checkout allocation snapshot has no valid signed allocation set.'
            );
        }

        $expected = [];
        foreach ($rawExpected as $allocation) {
            if (! is_array($allocation)) {
                throw new InvalidStockConfigurationException(
                    'The checkout allocation snapshot has an invalid signed allocation.'
                );
            }

            $allocationId = StrictInteger::parse(
                $allocation['allocation_id'] ?? null
            );
            $port = StrictInteger::parse($allocation['port'] ?? null);
            $ip = $allocation['ip'] ?? null;
            $environmentKey = $allocation['environment_key'] ?? null;
            $isPrimary = $allocation['is_primary'] ?? null;
            if (
                $allocationId === null
                || $allocationId <= 0
                || $port === null
                || $port <= 0
                || $port > 65535
                || ! is_string($ip)
                || trim($ip) === ''
                || (
                    $environmentKey !== null
                    && ! is_string($environmentKey)
                )
                || ! is_bool($isPrimary)
            ) {
                throw new InvalidStockConfigurationException(
                    'The checkout allocation snapshot has an invalid signed allocation.'
                );
            }

            $expected[] = [
                'panel_identity' => (string) $reservation->panel_identity,
                'node_id' => (int) $reservation->node_id,
                'allocation_id' => $allocationId,
                'ip' => $ip,
                'port' => $port,
                'environment_key' => $environmentKey,
                'is_primary' => $isPrimary,
            ];
        }
        usort(
            $expected,
            fn (array $left, array $right): int =>
                $left['allocation_id'] <=> $right['allocation_id']
        );

        $claimRows = collect($claims)->values();
        $actual = $claimRows
            ->map(fn (object $allocation): array => [
                'panel_identity' => (string) $allocation->panel_identity,
                'node_id' => (int) $allocation->node_id,
                'allocation_id' => (int) $allocation->allocation_id,
                'ip' => (string) ($allocation->ip ?? ''),
                'port' => (int) $allocation->port,
                'environment_key' => $allocation->environment_key,
                'is_primary' => (bool) $allocation->is_primary,
            ])
            ->sortBy('allocation_id')
            ->values()
            ->all();
        $allocationIds = array_column($actual, 'allocation_id');
        $status = (string) ($reservation->status ?? '');
        $releaseStateIsValid = match ($status) {
            'pending', 'paid_committed' => ! $claimRows->contains(
                fn (object $allocation): bool =>
                    $allocation->released_at !== null
            ),
            'confirmed' => ! $claimRows->contains(
                fn (object $allocation): bool =>
                    $allocation->released_at === null
            ),
            default => false,
        };

        if (
            $expected !== $actual
            || count(array_unique($allocationIds)) !== count($allocationIds)
            || collect($actual)->where('is_primary', true)->count() !== 1
            || ! $releaseStateIsValid
        ) {
            throw new InvalidStockConfigurationException(
                'Allocation claims no longer match the immutable checkout reservation.'
            );
        }

        return $payload;
    }

    private function canonicalMoney(mixed $unitPrice, int $quantity): string
    {
        if (
            $quantity !== 1
            || ! is_string($unitPrice)
            || preg_match('/^(0|[1-9]\d*)\.(\d{2})$/D', $unitPrice) !== 1
        ) {
            throw new DisplayException(
                'The dynamic product price is outside the supported invoice format.'
            );
        }

        $wholeDigits = strlen(strstr($unitPrice, '.', true));
        if ($wholeDigits > 15) {
            throw new DisplayException(
                'The dynamic product price exceeds the supported invoice range.'
            );
        }

        return $unitPrice;
    }

    /**
     * Prove that the service still owns the immutable checkout payload.
     *
     * Product defaults, config-option metadata, and server settings are
     * intentionally not recomputed here: administrators may edit them while a
     * seven-day quote is open. The signed reservation remains authoritative
     * and the provisioner overrides placement and resources from that snapshot.
     *
     * @param  object  $reservation
     */
    public function assertServiceMatches(Service $service, object $reservation): void
    {
        try {
            $payload = json_decode(
                (string) $reservation->configuration_payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'The capacity reservation snapshot is unreadable.',
                previous: $exception
            );
        }
        if (
            ! is_array($payload)
            || ! hash_equals(
                (string) $reservation->configuration_fingerprint,
                $this->fingerprint($payload)
            )
        ) {
            throw new \RuntimeException(
                'The capacity reservation snapshot failed its integrity check.'
            );
        }

        $reservationIdentity = [
            'customer_id' => (int) $reservation->user_id,
            'server_extension_id' => (int) $reservation->server_extension_id,
            'panel_identity' => (string) $reservation->panel_identity,
            'product_id' => (int) $reservation->product_id,
            'plan_id' => (int) $reservation->plan_id,
            'quantity' => (int) $reservation->quantity,
            'currency_code' => strtoupper((string) $reservation->currency_code),
            'user_id' => (int) $reservation->user_id,
            'memory' => (int) $reservation->memory,
            'cpu' => (int) $reservation->cpu,
            'disk' => (int) $reservation->disk,
            'location' => (int) $reservation->location_id,
            'node_id' => (int) $reservation->node_id,
        ];
        $payloadIdentity = [
            'customer_id' => StrictInteger::parse(
                $payload['customer_id'] ?? null
            ),
            'server_extension_id' => StrictInteger::parse(
                $payload['server_extension_id'] ?? null
            ),
            'panel_identity' => (string) ($payload['panel_identity'] ?? ''),
            'product_id' => StrictInteger::parse(
                $payload['product_id'] ?? null
            ),
            'plan_id' => StrictInteger::parse(
                $payload['plan_id'] ?? null
            ),
            'quantity' => StrictInteger::parse(
                $payload['quantity'] ?? null
            ),
            'currency_code' => strtoupper((string) ($payload['currency_code'] ?? '')),
            'user_id' => StrictInteger::parse(
                $payload['customer_id'] ?? null
            ),
            'memory' => StrictInteger::parse(
                data_get($payload, 'resources.memory')
            ),
            'cpu' => StrictInteger::parse(
                data_get($payload, 'resources.cpu')
            ),
            'disk' => StrictInteger::parse(
                data_get($payload, 'resources.disk')
            ),
            'location' => StrictInteger::parse(
                $payload['location_id'] ?? null
            ),
            'node_id' => StrictInteger::parse(
                $payload['node_id'] ?? null
            ),
        ];
        $serviceIdentity = [
            'service_id' => (int) $service->id,
            'product_id' => (int) $service->product_id,
            'plan_id' => (int) $service->plan_id,
            'quantity' => (int) $service->quantity,
            'currency_code' => strtoupper((string) $service->currency_code),
            'user_id' => (int) $service->user_id,
        ];
        $expectedServiceIdentity = [
            'service_id' => (int) $reservation->service_id,
            'product_id' => (int) $reservation->product_id,
            'plan_id' => (int) $reservation->plan_id,
            'quantity' => (int) $reservation->quantity,
            'currency_code' => strtoupper((string) $reservation->currency_code),
            'user_id' => (int) $reservation->user_id,
        ];

        if (
            $reservationIdentity !== $payloadIdentity
            || $serviceIdentity !== $expectedServiceIdentity
            || (int) $reservation->quantity !== 1
            || (int) $service->quantity !== 1
        ) {
            throw new \RuntimeException(
                'The service identity does not match its immutable capacity reservation.'
            );
        }

        $provisioningIdentity = (array) (
            $payload['provisioning_identity'] ?? []
        );
        if (
            StrictInteger::parse($provisioningIdentity['nest_id'] ?? null) === null
            || (int) $provisioningIdentity['nest_id'] <= 0
            || StrictInteger::parse($provisioningIdentity['egg_id'] ?? null) === null
            || (int) $provisioningIdentity['egg_id'] <= 0
            || ! hash_equals(
                $this->pterodactylUserExternalId((int) $service->user_id),
                (string) ($provisioningIdentity['user_external_id'] ?? '')
            )
            || strtolower(trim((string) (
                $provisioningIdentity['user_email'] ?? ''
            ))) === ''
        ) {
            throw new \RuntimeException(
                'The provisioning identity does not match its immutable capacity reservation.'
            );
        }
    }

    /**
     * @return array{
     *     nest_id: int,
     *     egg_id: int,
     *     user_external_id: string|null,
     *     user_email: string|null
     * }
     */
    private function provisioningIdentity(
        Product $product,
        ?int $customerId
    ): array {
        $settings = ExtensionHelper::settingsToArray($product->settings);
        $nestId = StrictInteger::parse($settings['nest_id'] ?? null);
        $eggId = StrictInteger::parse($settings['egg_id'] ?? null);
        if (
            $nestId === null
            || $nestId <= 0
            || $eggId === null
            || $eggId <= 0
        ) {
            throw new DisplayException(
                'The dynamic product must have one valid Pterodactyl nest and egg.'
            );
        }

        return [
            'nest_id' => $nestId,
            'egg_id' => $eggId,
            'user_external_id' => $customerId === null
                ? null
                : $this->pterodactylUserExternalId($customerId),
            'user_email' => $customerId === null
                ? null
                : $this->paymenterUserEmail($customerId),
        ];
    }

    private function pterodactylUserExternalId(int $customerId): string
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException(
                'A valid Paymenter customer is required for provisioning.'
            );
        }

        return "paymenter-user-{$customerId}";
    }

    private function paymenterUserEmail(int $customerId): string
    {
        return $this->normalizeCustomerEmail((string) User::query()
            ->whereKey($customerId)
            ->value('email'));
    }

    private function normalizeCustomerEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException(
                'A valid Paymenter customer email is required for provisioning.'
            );
        }

        return $email;
    }

    /**
     * @return array{server_extension_id: int, panel_identity: string}
     */
    private function checkoutPanelIdentity(CartItem $cartItem): array
    {
        $server = $cartItem->product->server;
        $serverSettings = $server?->settings?->pluck('value', 'key') ?? collect();
        $capacityExtension = Extension::query()
            ->where('extension', 'DynamicPterodactyl')
            ->where('enabled', true)
            ->first();
        $capacitySettings = $capacityExtension?->settings()
            ->pluck('value', 'key') ?? collect();

        $provisioningUrl = $this->normalizePanelUrl((string) $serverSettings->get('host'));
        $capacityUrl = $this->normalizePanelUrl((string) $capacitySettings->get('pterodactyl_url'));

        if (
            $server === null
            || $server->extension !== 'Pterodactyl'
            || $provisioningUrl === ''
            || $capacityUrl === ''
            || ! hash_equals($provisioningUrl, $capacityUrl)
        ) {
            throw new DisplayException(
                'The capacity service and this product must use the same Pterodactyl panel.'
            );
        }

        return [
            'server_extension_id' => (int) $server->id,
            'panel_identity' => hash('sha256', $provisioningUrl),
        ];
    }

    private function normalizePanelUrl(string $url): string
    {
        if (trim($url) === '') {
            return '';
        }

        try {
            return PanelEndpointIdentity::canonicalUrl($url);
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    /**
     * @return array<string|int, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            } elseif (
                is_float($item)
                && is_finite($item)
                && floor($item) === $item
                && $item >= PHP_INT_MIN
                && $item <= PHP_INT_MAX
            ) {
                // JSON columns may normalize 4096.0 to 4096. Treat integral
                // numeric values identically before both persistence and
                // fingerprinting so a database round trip cannot invalidate
                // an otherwise immutable reservation.
                $value[$key] = (int) $item;
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
        );
    }
}
