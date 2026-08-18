<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\ConfigOption;
use App\Models\Product;
use App\Services\Service\CapacityConfigurationLockService;
use App\Support\PanelEndpointIdentity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidResourceSelectionException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;

class ProductResourceConfigurationService
{
    private ?PterodactylInventoryService $inventory;

    public function __construct(?PterodactylInventoryService $inventory = null)
    {
        $this->inventory = $inventory;
    }

    /**
     * Resolve and strictly validate the complete resource vector represented by
     * a product and the customer's current configuration.
     *
     * @param  array<int|string, mixed>  $submittedOptions
     * @return array{
     *     product_id: int,
     *     location_id: int,
     *     resources: array{memory: int, cpu: int, disk: int},
     *     sliders: array<string, array{config_option_id: int, min: int, max: int, step: int, default: int}>,
     *     allocation_count: int,
     *     required_ports: list<int>,
     *     allocation_mappings: list<array{environment_key: string, requested_port: int|null, is_primary: bool}>,
     *     allowed_port_ranges: list<array{from: int, to: int}>,
     *     dedicated_ip: bool
     * }
     */
    public function forQuote(Product $product, array $submittedOptions): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(
                fn (): array => $this->forQuote(
                    $product,
                    $submittedOptions
                ),
                5
            );
        }

        $product = app(CapacityConfigurationLockService::class)
            ->lockProduct((int) $product->id);
        $product->loadMissing([
            'settings',
            'server.settings',
        ]);

        if ($product->server === null || $product->server->extension !== 'Pterodactyl') {
            throw new InvalidStockConfigurationException(
                'This product does not use the Pterodactyl server extension.'
            );
        }

        $provisioningUrl = $this->normalizePanelUrl((string) (
            $product->server->settings?->firstWhere('key', 'host')?->value ?? ''
        ));
        if (
            $provisioningUrl === ''
            || ! hash_equals(
                $this->inventory()->panelIdentity(),
                hash('sha256', $provisioningUrl)
            )
        ) {
            throw new InvalidStockConfigurationException(
                'The product and stock service are not configured for the same Pterodactyl panel.'
            );
        }
        $this->inventory()->assertExclusiveProvisioningControl();

        $selected = $this->normalizeSubmittedOptions($submittedOptions);
        $activeOptions = $this->activeProductOptions($product);
        if (array_diff(array_keys($selected), $activeOptions->modelKeys()) !== []) {
            throw new InvalidResourceSelectionException(
                'A submitted configuration option is not available for this product.'
            );
        }

        $settings = $product->settings
            ->mapWithKeys(fn ($setting): array => [
                strtolower((string) $setting->key) => $setting->value,
            ]);
        if (! in_array($settings->get('node'), [null, '', 0, '0'], true)) {
            throw new InvalidStockConfigurationException(
                'Dynamic stock products must not be pinned to a Pterodactyl node.'
            );
        }
        if (trim((string) ($settings->get('cpu_pinning') ?? '')) !== '') {
            throw new InvalidStockConfigurationException(
                'Dynamic CPU stock cannot be combined with static CPU pinning.'
            );
        }
        $resources = [];
        $sliders = [];
        $locationId = null;

        foreach ($activeOptions as $option) {
            $resource = strtolower((string) $option->getMetadata('resource_type', ''));

            if ($option->type === 'dynamic_slider' && in_array(
                $resource,
                ['memory', 'cpu', 'disk'],
                true
            )) {
                if (isset($sliders[$resource])) {
                    throw new InvalidStockConfigurationException(
                        "The product has more than one {$resource} slider."
                    );
                }

                $slider = $this->sliderConfiguration($option);
                $value = array_key_exists((int) $option->id, $selected)
                    ? $this->selectionInteger(
                        $selected[(int) $option->id],
                        "{$resource} selection"
                    )
                    : $slider['default'];

                if (
                    $value < $slider['min']
                    || $value > $slider['max']
                    || (($value - $slider['min']) % $slider['step']) !== 0
                ) {
                    throw new InvalidResourceSelectionException(
                        "The selected {$resource} value is outside its allowed range or step."
                    );
                }

                $resources[$resource] = $value;
                $sliders[$resource] = $slider;
            }

            if ($this->isLocationOption($option)) {
                if ($locationId !== null) {
                    throw new InvalidStockConfigurationException(
                        'The product has more than one location option.'
                    );
                }

                $locationId = $this->selectedLocation($option, $selected);
            }
        }

        foreach (['memory', 'cpu', 'disk'] as $resource) {
            if (isset($resources[$resource])) {
                continue;
            }

            $resources[$resource] = $this->positiveConfigurationInteger(
                $settings->get($resource),
                "product {$resource}"
            );
        }

        if ($sliders === []) {
            throw new InvalidStockConfigurationException(
                'This product does not have a dynamic resource slider.'
            );
        }

        $locationId ??= $this->staticLocation($settings->get('location_ids'));

        $portArray = $settings->get('port_array');
        $dedicatedIp = $this->configurationBoolean(
            $settings->get('dedicated_ip'),
            'dedicated IP'
        );
        $allowedPortRanges = $this->portRanges(
            $settings->get('port_range')
        );
        if (
            $portArray !== null
            && $portArray !== ''
            && ($dedicatedIp || $allowedPortRanges !== [])
        ) {
            throw new InvalidStockConfigurationException(
                'A product cannot combine a port array with dedicated IP or port-range deployment.'
            );
        }
        $additionalAllocations = $this->nonNegativeConfigurationInteger(
            $settings->get('additional_allocations') ?? 0,
            'additional allocations'
        );
        if ($additionalAllocations > 100) {
            throw new InvalidStockConfigurationException(
                'A product cannot reserve more than 100 additional allocations.'
            );
        }
        $allocationRequirements = $this->allocationRequirements(
            $portArray,
            $additionalAllocations
        );

        return [
            'product_id' => (int) $product->id,
            'location_id' => $locationId,
            'resources' => [
                'memory' => $resources['memory'],
                'cpu' => $resources['cpu'],
                'disk' => $resources['disk'],
            ],
            'sliders' => $sliders,
            'allocation_count' => $allocationRequirements['count'],
            'required_ports' => $allocationRequirements['ports'],
            'allocation_mappings' => $allocationRequirements['mappings'],
            'allowed_port_ranges' => $allowedPortRanges,
            'dedicated_ip' => $dedicatedIp,
        ];
    }

    /**
     * Resolve the current database truth instead of trusting a possibly stale
     * eager-loaded relation. Retired wizard options remain attached for
     * historical services, but hidden options and child values are not active
     * customer inputs.
     *
     * @return Collection<int, ConfigOption>
     */
    private function activeProductOptions(Product $product): Collection
    {
        return ConfigOption::query()
            ->whereHas(
                'products',
                fn ($query) => $query->whereKey($product->getKey())
            )
            ->where('hidden', false)
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{config_option_id: int, min: int, max: int, step: int, default: int}
     */
    private function sliderConfiguration(ConfigOption $option): array
    {
        $minimum = $this->positiveConfigurationInteger(
            $option->getMetadata('min'),
            "{$option->name} minimum"
        );
        $maximum = $this->positiveConfigurationInteger(
            $option->getMetadata('max'),
            "{$option->name} maximum"
        );
        $step = $this->positiveConfigurationInteger(
            $option->getMetadata('step'),
            "{$option->name} step"
        );
        $default = $this->positiveConfigurationInteger(
            $option->getMetadata('default'),
            "{$option->name} default"
        );

        if (
            $maximum < $minimum
            || (($maximum - $minimum) % $step) !== 0
            || $default < $minimum
            || $default > $maximum
            || (($default - $minimum) % $step) !== 0
        ) {
            throw new InvalidStockConfigurationException(
                "The {$option->name} slider metadata is inconsistent."
            );
        }

        return [
            'config_option_id' => (int) $option->id,
            'min' => $minimum,
            'max' => $maximum,
            'step' => $step,
            'default' => $default,
        ];
    }

    /**
     * @param  array<int, mixed>  $selected
     */
    private function selectedLocation(ConfigOption $option, array $selected): int
    {
        if (! array_key_exists((int) $option->id, $selected)) {
            throw new InvalidResourceSelectionException(
                'A deployment location must be selected.'
            );
        }

        $childId = $this->selectionInteger(
            $selected[(int) $option->id],
            'location selection'
        );
        $child = $option->availableChildren()->whereKey($childId)->first();
        if ($child === null) {
            throw new InvalidResourceSelectionException(
                'The selected deployment location is not available for this product.'
            );
        }

        return $this->positiveConfigurationInteger(
            $child->env_variable,
            'Pterodactyl location ID'
        );
    }

    private function isLocationOption(ConfigOption $option): bool
    {
        return strtolower(trim((string) $option->env_variable)) === 'location'
            || strtolower(trim((string) $option->name)) === 'location';
    }

    private function staticLocation(mixed $rawLocations): int
    {
        if (is_string($rawLocations)) {
            $decoded = json_decode($rawLocations, true);
            $rawLocations = json_last_error() === JSON_ERROR_NONE
                ? $decoded
                : $rawLocations;
        }

        $locations = is_array($rawLocations) ? array_values($rawLocations) : [$rawLocations];
        if (count($locations) !== 1) {
            throw new InvalidStockConfigurationException(
                'Products without a location option must have exactly one static location.'
            );
        }

        return $this->positiveConfigurationInteger(
            $locations[0],
            'static Pterodactyl location'
        );
    }

    /**
     * @return array{
     *     count: int,
     *     ports: list<int>,
     *     mappings: list<array{environment_key: string, requested_port: int|null, is_primary: bool}>
     * }
     */
    private function allocationRequirements(
        mixed $raw,
        int $additionalAllocations = 0
    ): array {
        if ($raw === null || $raw === '') {
            $mappings = [[
                'environment_key' => 'SERVER_PORT',
                'requested_port' => null,
                'is_primary' => true,
            ]];
            for ($index = 0; $index < $additionalAllocations; $index++) {
                $mappings[] = [
                    'environment_key' => 'NONE',
                    'requested_port' => null,
                    'is_primary' => false,
                ];
            }

            return [
                'count' => count($mappings),
                'ports' => [],
                'mappings' => $mappings,
            ];
        }

        if (! is_string($raw)) {
            throw new InvalidStockConfigurationException(
                'The product port mapping is invalid.'
            );
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || $decoded === []) {
            throw new InvalidStockConfigurationException(
                'The product port mapping is invalid.'
            );
        }

        $count = 0;
        $environmentKeys = [];
        $requestedPorts = [];
        $mappings = [];
        foreach ($decoded as $environmentKey => $ports) {
            if (
                ! is_string($environmentKey)
                || preg_match('/^[A-Z][A-Z0-9_]*$/', $environmentKey) !== 1
                || isset($environmentKeys[strtoupper($environmentKey)])
            ) {
                throw new InvalidStockConfigurationException(
                    'The product port mapping contains a duplicate or malformed environment key.'
                );
            }
            $environmentKeys[strtoupper($environmentKey)] = true;

            $ports = is_array($ports) ? array_values($ports) : [$ports];
            if (
                strtoupper($environmentKey) !== 'NONE'
                && count($ports) !== 1
            ) {
                throw new InvalidStockConfigurationException(
                    "The product port mapping may assign exactly one port to {$environmentKey}. "
                    .'Use NONE or additional allocations for unbound ports.'
                );
            }
            foreach ($ports as $index => $port) {
                $port = $this->positiveConfigurationInteger($port, 'port mapping');
                if ($port > 65535) {
                    throw new InvalidStockConfigurationException(
                        'The product port mapping contains an invalid port.'
                    );
                }
                if (isset($requestedPorts[$port])) {
                    throw new InvalidStockConfigurationException(
                        'The product port mapping cannot request the same port more than once.'
                    );
                }
                $requestedPorts[$port] = true;
                $mappings[] = [
                    'environment_key' => $environmentKey,
                    'requested_port' => $port,
                    'is_primary' => $environmentKey === 'SERVER_PORT' && $index === 0,
                ];
                $count++;
            }
        }

        if (! isset($environmentKeys['SERVER_PORT'])) {
            throw new InvalidStockConfigurationException(
                'The product port mapping must define SERVER_PORT.'
            );
        }
        for ($index = 0; $index < $additionalAllocations; $index++) {
            $mappings[] = [
                'environment_key' => 'NONE',
                'requested_port' => null,
                'is_primary' => false,
            ];
            $count++;
        }

        return [
            'count' => max(1, $count),
            'ports' => array_map('intval', array_keys($requestedPorts)),
            'mappings' => $mappings,
        ];
    }

    /**
     * @return list<array{from: int, to: int}>
     */
    private function portRanges(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = json_last_error() === JSON_ERROR_NONE
                ? $decoded
                : explode(',', $raw);
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidStockConfigurationException(
                'The product port-range configuration must be a list.'
            );
        }

        $ranges = [];
        foreach ($raw as $range) {
            if (
                ! is_string($range)
                || preg_match(
                    '/^\s*(\d{1,5})(?:\s*-\s*(\d{1,5}))?\s*$/',
                    $range,
                    $matches
                ) !== 1
            ) {
                throw new InvalidStockConfigurationException(
                    'Each product port range must be a port or inclusive start-end pair.'
                );
            }
            $from = (int) $matches[1];
            $to = isset($matches[2]) && $matches[2] !== ''
                ? (int) $matches[2]
                : $from;
            if ($from < 1 || $to > 65535 || $from > $to) {
                throw new InvalidStockConfigurationException(
                    'Each product port range must stay between 1 and 65535.'
                );
            }
            $ranges[] = ['from' => $from, 'to' => $to];
        }

        usort(
            $ranges,
            fn (array $left, array $right): int => [$left['from'], $left['to']] <=> [$right['from'], $right['to']]
        );
        $merged = [];
        foreach ($ranges as $range) {
            $last = array_key_last($merged);
            if (
                $last !== null
                && $range['from'] <= $merged[$last]['to'] + 1
            ) {
                $merged[$last]['to'] = max(
                    $merged[$last]['to'],
                    $range['to']
                );

                continue;
            }
            $merged[] = $range;
        }

        return $merged;
    }

    private function configurationBoolean(mixed $value, string $field): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
        }

        throw new InvalidStockConfigurationException(
            "The {$field} configuration must be a boolean."
        );
    }

    /**
     * Accept Paymenter's list of {option_id,value} entries and the keyed map
     * emitted by the quote client.
     *
     * @param  array<int|string, mixed>  $submitted
     * @return array<int, mixed>
     */
    private function normalizeSubmittedOptions(array $submitted): array
    {
        $normalized = [];

        if (array_is_list($submitted)) {
            foreach ($submitted as $entry) {
                if (! is_array($entry) || ! array_key_exists('option_id', $entry)) {
                    throw new InvalidResourceSelectionException(
                        'Configuration options must identify an option and value.'
                    );
                }

                $optionId = $this->selectionInteger(
                    $entry['option_id'],
                    'configuration option ID'
                );
                if (array_key_exists($optionId, $normalized)) {
                    throw new InvalidResourceSelectionException(
                        'A configuration option cannot be submitted more than once.'
                    );
                }
                $normalized[$optionId] = $entry['value'] ?? null;
            }

            return $normalized;
        }

        foreach ($submitted as $optionId => $value) {
            $normalized[$this->selectionInteger(
                $optionId,
                'configuration option ID'
            )] = $value;
        }

        return $normalized;
    }

    private function positiveConfigurationInteger(mixed $value, string $field): int
    {
        try {
            $value = $this->strictInteger($value);
        } catch (\InvalidArgumentException) {
            throw new InvalidStockConfigurationException(
                "The {$field} configuration must be an integer."
            );
        }

        if ($value <= 0) {
            throw new InvalidStockConfigurationException(
                "The {$field} configuration must be positive."
            );
        }

        return $value;
    }

    private function nonNegativeConfigurationInteger(mixed $value, string $field): int
    {
        try {
            $value = $this->strictInteger($value);
        } catch (\InvalidArgumentException) {
            throw new InvalidStockConfigurationException(
                "The {$field} configuration must be an integer."
            );
        }

        if ($value < 0) {
            throw new InvalidStockConfigurationException(
                "The {$field} configuration cannot be negative."
            );
        }

        return $value;
    }

    private function selectionInteger(mixed $value, string $field): int
    {
        try {
            return $this->strictInteger($value);
        } catch (\InvalidArgumentException) {
            throw new InvalidResourceSelectionException(
                "The {$field} must be an integer."
            );
        }
    }

    private function strictInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && preg_match('/^(0|[1-9]\d*)$/', $value) === 1
        ) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if ($validated !== false) {
                return $validated;
            }
        }

        throw new \InvalidArgumentException('Expected an integer.');
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

    private function inventory(): PterodactylInventoryService
    {
        return $this->inventory ??= app(PterodactylInventoryService::class);
    }
}
