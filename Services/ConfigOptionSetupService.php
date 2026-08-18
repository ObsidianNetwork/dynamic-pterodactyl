<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Helpers\ExtensionHelper;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Rules\DynamicSliderMetadataRule;
use App\Support\PanelEndpointIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\Concerns\AuditsExtensionActions;

class ConfigOptionSetupService
{
    use AuditsExtensionActions;

    private array $resourceDefaults = [
        'memory' => [
            'min' => 1024,
            'max' => 65536,
            'step' => 1024,
            'default' => 4096,
            'unit' => 'MB',
            'display_unit' => 'GB',
            'display_divisor' => 1024,
        ],
        'cpu' => [
            'min' => 100,
            'max' => 800,
            'step' => 100,
            'default' => 200,
            'unit' => '%',
            'display_unit' => 'cores',
            'display_divisor' => 100,
        ],
        'disk' => [
            'min' => 10240,
            'max' => 102400,
            'step' => 10240,
            'default' => 20480,
            'unit' => 'MB',
            'display_unit' => 'GB',
            'display_divisor' => 1024,
        ],
    ];

    public function createDynamicSliderOptions(int $productId, array $config, array $locations = []): array
    {
        $created = DB::transaction(function () use ($productId, $config, $locations) {
            $product = Product::query()
                ->with(['plans.prices', 'settings', 'server'])
                ->lockForUpdate()
                ->findOrFail($productId);
            $this->assertEligibleProduct($product);
            $locations = $this->validatedLocations($product, $locations);
            $this->validateSingleCurrency($product);
            // Product is the shared first lock for quote readers and
            // configuration writers. Retire only unbound cart quotes before
            // guarded option/price mutations; invoice and upgrade commitments
            // remain immutable and deliberately block the wizard.
            $this->invalidateUnpaidReservations($productId);
            $this->synchronizePlanBasePrice($product, $config);

            $product->allow_quantity = 'disabled';
            $product->save();

            $out = [];

            foreach (['memory', 'cpu', 'disk'] as $resourceType) {
                $enableKey = "enable_{$resourceType}_slider";
                if (($config[$enableKey] ?? true) === false) {
                    $this->retireResourceOption($productId, $resourceType);

                    continue;
                }

                $out[$resourceType] = $this->createResourceOption(
                    $productId,
                    $resourceType,
                    $config
                );
            }

            $location = $this->synchronizeLocationOption($productId, $locations);
            if ($location !== null) {
                $out['location'] = $location;
            }

            return $out;
        }, 5);

        if (! empty($created)) {
            $this->safeAudit('setup_run', 'product_config', $productId, [
                'sliders_configured' => array_keys($created),
                'count' => count($created),
            ]);
        }

        return $created;
    }

    private function createResourceOption(int $productId, string $resourceType, array $config): ConfigOption
    {
        $defaults = $this->resourceDefaults[$resourceType] ?? [];

        $metadata = $this->buildResourceMetadata(
            $productId,
            $resourceType,
            $config,
            $defaults
        );

        $existingOption = $this->findExistingOption($productId, $resourceType);

        if ($existingOption) {
            $existingOption->update([
                'type' => 'dynamic_slider',
                'env_variable' => $resourceType,
                'hidden' => false,
                'upgradable' => true,
                'metadata' => $metadata,
            ]);

            return $existingOption;
        }

        $option = ConfigOption::create([
            'name' => ucfirst($resourceType),
            'type' => 'dynamic_slider',
            'env_variable' => $resourceType,
            'hidden' => false,
            'sort' => match ($resourceType) {
                'memory' => 1,
                'cpu' => 2,
                'disk' => 3,
                default => 10,
            },
            'parent_id' => null,
            'upgradable' => true,
            'metadata' => $metadata,
        ]);

        $option->products()->syncWithoutDetaching([$productId]);

        return $option;
    }

    private function buildResourceMetadata(
        int $productId,
        string $resourceType,
        array $config,
        array $defaults
    ): array {
        $pricingModel = $config['pricing_model'] ?? 'linear';

        $divisor = $defaults['display_divisor'] ?? 1;
        $pricing = $this->buildPricingMetadata($resourceType, $pricingModel, $config);

        $metadata = [
            'managed_by' => 'dynamic_pterodactyl',
            'managed_product_id' => $productId,
            'resource_type' => $resourceType,
            'min' => $this->scaleDisplayValue(
                $config["{$resourceType}_min"] ?? ($defaults['min'] / $divisor),
                $divisor,
                "{$resourceType} minimum"
            ),
            'max' => $this->scaleDisplayValue(
                $config["{$resourceType}_max"] ?? ($defaults['max'] / $divisor),
                $divisor,
                "{$resourceType} maximum"
            ),
            'step' => $this->scaleDisplayValue(
                $config["{$resourceType}_step"] ?? ($defaults['step'] / $divisor),
                $divisor,
                "{$resourceType} step"
            ),
            'default' => $this->scaleDisplayValue(
                $config["{$resourceType}_default"] ?? ($defaults['default'] / $divisor),
                $divisor,
                "{$resourceType} default"
            ),
            'unit' => $defaults['unit'],
            'display_unit' => $defaults['display_unit'],
            'display_divisor' => $defaults['display_divisor'],
            'pricing' => $pricing,
        ];

        // Pterodactyl interprets zero RAM, CPU, or disk as an unlimited
        // resource. Dynamic stock must never allow a customer-selectable zero
        // minimum to bypass the finite inventory contract.
        if ($metadata['min'] <= 0) {
            throw new \InvalidArgumentException(
                ucfirst($resourceType).' minimum must be greater than zero.'
            );
        }

        $errors = [];
        (new DynamicSliderMetadataRule)->validate(
            'metadata',
            $metadata,
            function (string $message) use (&$errors): void {
                $errors[] = $message;
            }
        );

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }

        return $metadata;
    }

    private function buildPricingMetadata(string $resourceType, string $pricingModel, array $config): array
    {
        $pricing = [
            'model' => $pricingModel,
        ];

        switch ($pricingModel) {
            case 'linear':
                $pricing['rate_per_unit'] = $this->nonNegativeDecimal(
                    $config["{$resourceType}_rate"] ?? 0,
                    "{$resourceType} rate"
                );
                break;

            case 'tiered':
                $pricing['tiers'] = collect(
                    $config["{$resourceType}_tiers"] ?? []
                )->values()->map(function ($tier, int $index) use ($resourceType): array {
                    if (! is_array($tier)) {
                        throw new \InvalidArgumentException(
                            ucfirst($resourceType).' pricing tiers must be arrays.'
                        );
                    }

                    return [
                        'up_to' => ($tier['up_to'] ?? null) === null
                            ? null
                            : $this->nonNegativeDecimal(
                                $tier['up_to'],
                                "{$resourceType} tier ".($index + 1).' limit'
                            ),
                        'rate' => $this->nonNegativeDecimal(
                            $tier['rate'] ?? null,
                            "{$resourceType} tier ".($index + 1).' rate'
                        ),
                    ];
                })->all();
                break;

            case 'base_addon':
                $pricing['included_units'] = $this->nonNegativeDecimal(
                    $config["{$resourceType}_included"] ?? 0,
                    "{$resourceType} included units"
                );
                $pricing['overage_rate'] = $this->nonNegativeDecimal(
                    $config["{$resourceType}_overage"] ?? 0,
                    "{$resourceType} overage rate"
                );
                break;

            default:
                throw new \InvalidArgumentException(
                    'Unknown dynamic resource pricing model.'
                );
        }

        return $pricing;
    }

    private function synchronizeLocationOption(int $productId, array $locations): ?ConfigOption
    {
        $existingLocation = $this->findExistingOption(
            $productId,
            'location',
            $locations !== []
        );

        if ($locations === []) {
            if ($existingLocation !== null) {
                $existingLocation->update([
                    'hidden' => true,
                    'upgradable' => false,
                ]);
                $existingLocation->children()->update(['hidden' => true]);
            }

            return null;
        }

        if ($existingLocation) {
            $locationOption = $existingLocation;
            $locationOption->update([
                'env_variable' => 'location',
                'hidden' => false,
                'upgradable' => false,
                'metadata' => array_merge(
                    (array) ($locationOption->metadata ?? []),
                    [
                        'managed_by' => 'dynamic_pterodactyl',
                        'managed_product_id' => $productId,
                        'resource_type' => 'location',
                    ]
                ),
            ]);
        } else {
            $locationOption = ConfigOption::create([
                'name' => 'Location',
                'type' => 'select',
                'env_variable' => 'location',
                'hidden' => false,
                'sort' => 0,
                'parent_id' => null,
                'upgradable' => false,
                'metadata' => [
                    'managed_by' => 'dynamic_pterodactyl',
                    'managed_product_id' => $productId,
                    'resource_type' => 'location',
                ],
            ]);

            $locationOption->products()->syncWithoutDetaching([$productId]);
        }

        $selectedLocationIds = [];
        foreach ($locations as $loc) {
            if (! isset($loc['id']) || ! is_numeric($loc['id'])) {
                throw new \InvalidArgumentException('Every selected location must have a numeric Pterodactyl ID.');
            }

            $selectedLocationIds[] = (string) $loc['id'];
            $locationName = $loc['long'] ?: $loc['short'];
            ConfigOption::updateOrCreate([
                'parent_id' => $locationOption->id,
                'env_variable' => (string) $loc['id'],
            ], [
                'name' => $locationName,
                'type' => 'option',
                'hidden' => false,
                'sort' => 0,
                'metadata' => [
                    'managed_by' => 'dynamic_pterodactyl',
                    'managed_product_id' => $productId,
                    'managed_location_id' => (int) $loc['id'],
                ],
            ]);
        }

        $locationOption->children()
            ->whereNotIn('env_variable', $selectedLocationIds)
            ->update(['hidden' => true]);

        return $locationOption;
    }

    private function retireResourceOption(int $productId, string $resourceType): void
    {
        $option = $this->findExistingOption($productId, $resourceType, false);
        if ($option === null) {
            return;
        }

        $option->update([
            'hidden' => true,
            'upgradable' => false,
        ]);
    }

    private function synchronizePlanBasePrice(Product $product, array $config): void
    {
        $monthlyBase = $this->nonNegativeDecimal(
            $config['base_price'] ?? 0,
            'monthly base price'
        );

        foreach ($product->plans as $plan) {
            $multiplier = match ($plan->billing_unit) {
                'day' => (int) $plan->billing_period / 30,
                'week' => (int) $plan->billing_period / 4,
                'year' => (int) $plan->billing_period * 12,
                default => max(1, (int) $plan->billing_period),
            };

            $plan->dynamic_slider_base_price = round($monthlyBase * $multiplier, 2);
            $plan->save();
        }
    }

    private function validateSingleCurrency(Product $product): void
    {
        $currencies = $product->plans
            ->flatMap(fn ($plan) => $plan->prices->pluck('currency_code'))
            ->filter()
            ->unique()
            ->values();

        if ($currencies->count() > 1) {
            throw new \InvalidArgumentException(
                'Dynamic slider pricing currently supports one product currency. Configure separate products for each currency.'
            );
        }
    }

    public function checkExistingOptions(int $productId): array
    {
        $resourceTypes = ['memory', 'cpu', 'disk'];

        $existingTypes = [];
        foreach ($this->productOptions($productId) as $option) {
            $metadata = (array) ($option->metadata ?? []);
            $resourceType = strtolower((string) ($metadata['resource_type'] ?? ''));
            if (
                $option->type === 'dynamic_slider'
                && ($metadata['managed_by'] ?? null) === 'dynamic_pterodactyl'
                && in_array($resourceType, $resourceTypes, true)
            ) {
                $existingTypes[$resourceType] = $option->id;
            }
        }

        return [
            'has_existing' => count($existingTypes) > 0,
            'existing_types' => $existingTypes,
            'existing_count' => count($existingTypes),
        ];
    }

    private function findExistingOption(
        int $productId,
        string $name,
        bool $failOnConflict = true
    ): ?ConfigOption {
        $name = strtolower($name);
        $candidates = $this->productOptions($productId)
            ->filter(function (ConfigOption $option) use ($name): bool {
                $metadata = (array) ($option->metadata ?? []);

                return ($metadata['managed_by'] ?? null) === 'dynamic_pterodactyl'
                    && (
                        strtolower((string) ($metadata['resource_type'] ?? '')) === $name
                        || (
                            $name === 'location'
                            && strtolower((string) $option->env_variable) === 'location'
                        )
                    );
            })
            ->values();

        if ($candidates->count() > 1) {
            throw new \InvalidArgumentException(
                "This product has multiple Dynamic Pterodactyl {$name} options."
            );
        }
        if ($candidates->count() === 1) {
            $option = $candidates->first();
            $metadata = (array) ($option->metadata ?? []);
            $owner = $metadata['managed_product_id'] ?? null;
            if ($owner !== null && (int) $owner !== $productId) {
                throw new \InvalidArgumentException(
                    "The managed {$name} option belongs to a different product."
                );
            }

            // One-time adoption of options created by an earlier plugin version
            // is permitted only when the explicit managed_by marker is present.
            if ($owner === null) {
                $option->metadata = array_merge($metadata, [
                    'managed_product_id' => $productId,
                    'resource_type' => $name,
                ]);
                $option->save();
            }

            return $option;
        }

        $conflict = $this->productOptions($productId)->first(
            fn (ConfigOption $option): bool => strtolower((string) $option->env_variable) === $name
                || strtolower((string) $option->name) === $name
        );
        if ($conflict !== null && $failOnConflict) {
            throw new \InvalidArgumentException(
                "An unmanaged {$name} configuration option already exists. Rename or remove it before running this wizard."
            );
        }

        return null;
    }

    private function productOptions(int $productId)
    {
        return ConfigOption::query()
            ->whereNull('parent_id')
            ->whereHas(
                'products',
                fn ($query) => $query->whereKey($productId)
            )
            ->get();
    }

    private function assertEligibleProduct(Product $product): void
    {
        if ($product->hidden) {
            throw new \InvalidArgumentException(
                'Dynamic stock cannot be configured on a hidden product.'
            );
        }
        if (
            $product->server === null
            || ! $product->server->enabled
            || $product->server->extension !== 'Pterodactyl'
        ) {
            throw new \InvalidArgumentException(
                'Dynamic stock requires an enabled Pterodactyl server extension.'
            );
        }

        $settings = ExtensionHelper::settingsToArray(
            $product->server->settings
        );
        $host = trim((string) ($settings['host'] ?? ''));
        try {
            $panelIdentity = PanelEndpointIdentity::hash($host);
        } catch (\InvalidArgumentException) {
            $panelIdentity = '';
        }
        if (
            $panelIdentity === ''
            || ! hash_equals(
                app(PterodactylInventoryService::class)->panelIdentity(),
                $panelIdentity
            )
        ) {
            throw new \InvalidArgumentException(
                'The product provisioner and Dynamic Pterodactyl stock service must target the same panel.'
            );
        }
    }

    /**
     * Re-resolve every selected location from the authoritative panel. When no
     * customer choice is configured, the product must have one static location.
     *
     * @return list<array{id: int, short: string, long: string}>
     */
    private function validatedLocations(Product $product, array $locations): array
    {
        $available = collect(app(PterodactylInventoryService::class)->locations())
            ->keyBy('id');

        if ($locations !== []) {
            $validated = [];
            foreach ($locations as $location) {
                $id = is_array($location)
                    ? ($location['id'] ?? null)
                    : null;
                if (! is_int($id) && ! (
                    is_string($id)
                    && preg_match('/^[1-9]\d*$/D', $id) === 1
                )) {
                    throw new \InvalidArgumentException(
                        'Every selected location must have a positive integer ID.'
                    );
                }

                $authoritative = $available->get((int) $id);
                if (! is_array($authoritative)) {
                    throw new \InvalidArgumentException(
                        'A selected location no longer exists in Pterodactyl.'
                    );
                }
                $validated[(int) $id] = $authoritative;
            }

            return array_values($validated);
        }

        $raw = $product->settings
            ->firstWhere('key', 'location_ids')
            ?->value;
        $ids = is_array($raw) ? $raw : [$raw];
        $ids = collect($ids)
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(function ($id): int {
                if (! is_int($id) && ! (
                    is_string($id)
                    && preg_match('/^[1-9]\d*$/D', $id) === 1
                )) {
                    throw new \InvalidArgumentException(
                        'The product static location must be a positive integer.'
                    );
                }

                return (int) $id;
            })
            ->unique()
            ->values();

        if ($ids->count() !== 1 || ! $available->has($ids->first())) {
            throw new \InvalidArgumentException(
                'Select customer locations or configure exactly one valid static Pterodactyl location on the product.'
            );
        }

        return [];
    }

    private function scaleDisplayValue(
        mixed $value,
        int $divisor,
        string $label
    ): int {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException(
                "The {$label} display divisor is invalid."
            );
        }

        $text = is_int($value) || is_float($value)
            ? (string) $value
            : (is_string($value) ? $value : '');
        if (
            preg_match('/^(0|[1-9]\d*)(?:\.(\d+))?$/D', $text, $matches) !== 1
        ) {
            throw new \InvalidArgumentException(
                "The {$label} must be a non-negative decimal without exponent notation."
            );
        }

        $whole = filter_var(
            $matches[1],
            FILTER_VALIDATE_INT,
            FILTER_NULL_ON_FAILURE
        );
        if ($whole === null || $whole > intdiv(PHP_INT_MAX, $divisor)) {
            throw new \InvalidArgumentException(
                "The {$label} is outside the supported range."
            );
        }

        $scaled = $whole * $divisor;
        $fraction = $matches[2] ?? '';
        if ($fraction === '') {
            return $scaled;
        }
        if (strlen($fraction) > 18) {
            throw new \InvalidArgumentException(
                "The {$label} has more precision than the internal unit supports."
            );
        }

        $denominator = 10 ** strlen($fraction);
        $numerator = (int) $fraction;
        if ($numerator > intdiv(PHP_INT_MAX, $divisor)) {
            throw new \InvalidArgumentException(
                "The {$label} is outside the supported range."
            );
        }
        $fractionProduct = $numerator * $divisor;
        if ($fractionProduct % $denominator !== 0) {
            throw new \InvalidArgumentException(
                "The {$label} cannot be represented exactly in internal resource units."
            );
        }

        $fractionScaled = intdiv($fractionProduct, $denominator);
        if ($scaled > PHP_INT_MAX - $fractionScaled) {
            throw new \InvalidArgumentException(
                "The {$label} is outside the supported range."
            );
        }

        return $scaled + $fractionScaled;
    }

    private function nonNegativeDecimal(mixed $value, string $label): float
    {
        $text = is_int($value) || is_float($value)
            ? (string) $value
            : (is_string($value) ? $value : '');
        if (
            preg_match('/^(0|[1-9]\d*)(?:\.\d+)?$/D', $text) !== 1
            || ! is_finite((float) $text)
            || (float) $text < 0
        ) {
            throw new \InvalidArgumentException(
                "The {$label} must be a finite non-negative decimal without exponent notation."
            );
        }

        return (float) $text;
    }

    private function invalidateUnpaidReservations(int $productId): void
    {
        $candidateQuery = DB::table('ptero_resource_reservations')
            ->where('product_id', $productId)
            ->where('status', 'pending')
            ->whereNull('service_id')
            ->whereNull('invoice_id');
        if (Schema::hasColumn('ptero_resource_reservations', 'service_upgrade_id')) {
            $candidateQuery->whereNull('service_upgrade_id');
        }
        $candidateIds = $candidateQuery->orderBy('id')->pluck('id');

        foreach ($candidateIds as $candidateId) {
            $reservation = DB::table('ptero_resource_reservations')
                ->where('id', $candidateId)
                ->lockForUpdate()
                ->first();
            if (
                $reservation === null
                || $reservation->status !== 'pending'
                || (int) $reservation->product_id !== $productId
                || $reservation->service_id !== null
                || $reservation->invoice_id !== null
                || (
                    property_exists($reservation, 'service_upgrade_id')
                    && $reservation->service_upgrade_id !== null
                )
            ) {
                continue;
            }

            $updates = [
                'status' => 'cancelled',
                'admin_notes' => 'Unbound cart quote invalidated after dynamic product configuration changed.',
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('ptero_resource_reservations', 'upgrade_guard_id')) {
                $updates['upgrade_guard_id'] = null;
            }
            DB::table('ptero_resource_reservations')
                ->where('id', $reservation->id)
                ->where('status', 'pending')
                ->update($updates);

            if (Schema::hasTable('ptero_reservation_allocations')) {
                DB::table('ptero_reservation_allocations')
                    ->where('reservation_id', $reservation->id)
                    ->whereNull('released_at')
                    ->update([
                        'released_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public static function getProductsWithSlidersCount(): int
    {
        return DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_options.type', 'dynamic_slider')
            ->where('config_options.hidden', false)
            ->whereNull('config_options.parent_id')
            ->distinct('config_option_products.product_id')
            ->count('config_option_products.product_id');
    }
}
