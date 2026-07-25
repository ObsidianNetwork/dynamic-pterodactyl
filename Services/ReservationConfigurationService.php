<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\CartItem;
use App\Models\ConfigOption;
use App\Models\Extension;
use App\Models\Product;
use App\Models\Service;

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
     *     calculated_price: float,
     *     pricing_version: string,
     *     formula_version: string,
     *     config_options: array<int, array<string, mixed>>
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

        $selectedOptions = collect($cartItem->config_options ?? [])
            ->keyBy(fn ($option) => (int) data_get($option, 'option_id'));

        $resources = [];
        $locationId = null;
        $configurationOptions = [];

        foreach ($cartItem->product->configOptions as $option) {
            $selection = $selectedOptions->get((int) $option->id);
            $value = data_get($selection, 'value');
            $resourceType = strtolower((string) $option->getMetadata('resource_type', ''));
            $environmentKey = strtolower((string) ($option->env_variable ?: $option->name));

            if ($option->type === 'dynamic_slider' && in_array($resourceType, ['memory', 'cpu', 'disk'], true)) {
                $minimum = $resourceType === 'cpu' ? 0 : 1;
                if ($value === null || ! is_numeric($value) || (float) $value < $minimum) {
                    throw new DisplayException("A valid {$resourceType} selection is required.");
                }

                $resources[$resourceType] = (int) $value;
            }

            if ($environmentKey === 'location' || strtolower($option->name) === 'location') {
                $locationId = $this->resolveLocationId($option, $value);
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

        $staticResources = $this->resolveStaticResources($cartItem);
        foreach (['memory', 'cpu', 'disk'] as $requiredResource) {
            if (! array_key_exists($requiredResource, $resources)) {
                $resources[$requiredResource] = $staticResources[$requiredResource]
                    ?? throw new DisplayException(
                        "The {$requiredResource} resource is missing from both the slider and product settings."
                    );
            }
        }

        $locationId ??= $this->resolveStaticLocationId($cartItem);
        if ($locationId === null || $locationId <= 0) {
            throw new DisplayException('A deployment location is required before this product can be reserved.');
        }

        usort($configurationOptions, fn (array $left, array $right) => $left['id'] <=> $right['id']);

        $calculatedPrice = round((float) $cartItem->price->total * (int) $cartItem->quantity, 2);
        $currencyCode = strtoupper((string) $cartItem->cart->currency_code);
        $panel = $this->checkoutPanelIdentity($cartItem);
        $pricingIdentity = [
            'product_id' => (int) $cartItem->product_id,
            'plan_id' => (int) $cartItem->plan_id,
            'currency_code' => $currencyCode,
            'calculated_price' => $calculatedPrice,
            'config_options' => $configurationOptions,
        ];

        return [
            'customer_id' => $cartItem->cart->user_id !== null
                ? (int) $cartItem->cart->user_id
                : (auth()->id() !== null ? (int) auth()->id() : null),
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
        ];
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
            ->whereNull('parent_id')
            ->get()
            ->contains(fn (ConfigOption $option) => in_array(
                strtolower((string) $option->getMetadata('resource_type', '')),
                ['memory', 'cpu', 'disk'],
                true
            ));
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
        ]);
    }

    /**
     * Transfer a guest payload to its authenticated customer without changing
     * any pricing, placement, or resource input.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withCustomer(array $payload, int $customerId): array
    {
        $payload['customer_id'] = $customerId;

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
     * Prove that the service still represents the immutable checkout payload.
     *
     * @param  object  $reservation
     */
    public function assertServiceMatches(Service $service, object $reservation): void
    {
        $expected = [
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
        ];

        $properties = collect(ExtensionHelper::getServiceProperties($service))
            ->mapWithKeys(fn ($value, $key) => [strtolower((string) $key) => $value]);
        $settings = $service->product->settings()
            ->get(['key', 'value'])
            ->mapWithKeys(fn ($setting) => [strtolower((string) $setting->key) => $setting->value]);
        $server = $service->product->server;
        $serverSettings = $server?->settings
            ?->pluck('value', 'key') ?? collect();

        $actual = [
            'server_extension_id' => (int) $service->product->server_id,
            'panel_identity' => hash(
                'sha256',
                $this->normalizePanelUrl((string) $serverSettings->get('host'))
            ),
            'product_id' => (int) $service->product_id,
            'plan_id' => (int) $service->plan_id,
            'quantity' => (int) $service->quantity,
            'currency_code' => strtoupper((string) $service->currency_code),
            'user_id' => (int) $service->user_id,
            'memory' => (int) ($properties->get('memory') ?? $settings->get('memory')),
            'cpu' => (int) ($properties->get('cpu') ?? $settings->get('cpu')),
            'disk' => (int) ($properties->get('disk') ?? $settings->get('disk')),
            'location' => $this->serviceLocationId($service, $properties->get('location')),
        ];

        if ($expected !== $actual) {
            throw new \RuntimeException('The service configuration does not match its capacity reservation.');
        }
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
        return strtolower(rtrim(trim($url), '/'));
    }

    private function resolveLocationId(ConfigOption $option, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($option->type === 'dynamic_slider') {
            return is_numeric($value) ? (int) $value : null;
        }

        $selectedChild = $option->children->firstWhere('id', (int) $value);
        $resolved = $selectedChild?->env_variable ?? $value;

        return is_numeric($resolved) ? (int) $resolved : null;
    }

    private function resolveStaticLocationId(CartItem $cartItem): ?int
    {
        $settings = $cartItem->product->settings()->pluck('value', 'key');
        $locations = $settings->get('location_ids');

        if (is_string($locations)) {
            $decoded = json_decode($locations, true);
            $locations = json_last_error() === JSON_ERROR_NONE ? $decoded : $locations;
        }

        $locations = is_array($locations) ? array_values($locations) : [$locations];
        $locations = array_values(array_filter($locations, fn ($location) => is_numeric($location)));

        return count($locations) === 1 ? (int) $locations[0] : null;
    }

    /**
     * Static Pterodactyl limits fill resources whose sliders are intentionally
     * disabled. They become part of the same immutable payload.
     *
     * @return array{memory?: int, cpu?: int, disk?: int}
     */
    private function resolveStaticResources(CartItem $cartItem): array
    {
        $settings = $cartItem->product->settings()
            ->get(['key', 'value'])
            ->mapWithKeys(fn ($setting) => [strtolower((string) $setting->key) => $setting->value]);
        $resources = [];

        foreach (['memory', 'cpu', 'disk'] as $resourceType) {
            $value = $settings->get($resourceType);
            $minimum = $resourceType === 'cpu' ? 0 : 1;

            if (is_numeric($value) && (float) $value >= $minimum) {
                $resources[$resourceType] = (int) $value;
            }
        }

        return $resources;
    }

    private function serviceLocationId(Service $service, mixed $propertyLocation): int
    {
        if (is_numeric($propertyLocation)) {
            return (int) $propertyLocation;
        }

        $locations = $service->product->settings()->where('key', 'location_ids')->value('value');
        if (is_string($locations)) {
            $decoded = json_decode($locations, true);
            $locations = json_last_error() === JSON_ERROR_NONE ? $decoded : $locations;
        }

        $locations = is_array($locations) ? array_values($locations) : [$locations];
        $locations = array_values(array_filter($locations, fn ($location) => is_numeric($location)));

        return count($locations) === 1 ? (int) $locations[0] : 0;
    }

    /**
     * @return array<string|int, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
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
