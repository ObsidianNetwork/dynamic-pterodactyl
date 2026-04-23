<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\ConfigOption;
use App\Rules\DynamicSliderPricingRule;
use Illuminate\Support\Facades\DB;

class ConfigOptionSetupService
{
    public function __construct(
        private AuditLogService $audit,
    ) {}

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
            $out = [];

            foreach (['memory', 'cpu', 'disk'] as $resourceType) {
                $enableKey = "enable_{$resourceType}_slider";
                if (($config[$enableKey] ?? true) === false) {
                    continue;
                }

                $out[$resourceType] = $this->createResourceOption(
                    $productId,
                    $resourceType,
                    $config
                );
            }

            if (! empty($locations)) {
                $out['location'] = $this->createLocationOption($productId, $locations);
            }

            return $out;
        });

        if (! empty($created)) {
            try {
                $this->audit->log('setup_run', 'product_config', $productId, [
                    'sliders_configured' => array_keys($created),
                    'count' => count($created),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $created;
    }

    private function createResourceOption(int $productId, string $resourceType, array $config): ConfigOption
    {
        $defaults = $this->resourceDefaults[$resourceType] ?? [];

        $metadata = $this->buildResourceMetadata($resourceType, $config, $defaults);

        $existingOption = $this->findExistingOption($productId, $resourceType);

        if ($existingOption) {
            $existingOption->update([
                'type' => 'dynamic_slider',
                'metadata' => $metadata,
            ]);

            return $existingOption;
        }

        $option = ConfigOption::create([
            'name' => ucfirst($resourceType),
            'type' => 'dynamic_slider',
            'env_variable' => strtoupper($resourceType),
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

    private function buildResourceMetadata(string $resourceType, array $config, array $defaults): array
    {
        $pricingModel = $config['pricing_model'] ?? 'linear';

        $divisor = $defaults['display_divisor'] ?? 1;
        $pricing = $this->buildPricingMetadata($resourceType, $pricingModel, $config);

        $errors = [];
        (new DynamicSliderPricingRule())->validate('metadata.pricing', $pricing, function (string $message) use (&$errors) {
            $errors[] = $message;
        });

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }

        $metadata = [
            'resource_type' => $resourceType,
            'min' => (int) (($config["{$resourceType}_min"] ?? ($defaults['min'] / $divisor)) * $divisor),
            'max' => (int) (($config["{$resourceType}_max"] ?? ($defaults['max'] / $divisor)) * $divisor),
            'step' => (int) (($config["{$resourceType}_step"] ?? ($defaults['step'] / $divisor)) * $divisor),
            'default' => (int) (($config["{$resourceType}_default"] ?? ($defaults['default'] / $divisor)) * $divisor),
            'unit' => $defaults['unit'],
            'display_unit' => $defaults['display_unit'],
            'display_divisor' => $defaults['display_divisor'],
            'pricing' => $pricing,
        ];

        return $metadata;
    }

    private function buildPricingMetadata(string $resourceType, string $pricingModel, array $config): array
    {
        $pricing = [
            'model' => $pricingModel,
            'base_price' => (float) ($config['base_price'] ?? 0),
        ];

        switch ($pricingModel) {
            case 'linear':
                $pricing['rate_per_unit'] = (float) ($config["{$resourceType}_rate"] ?? 0);
                break;

            case 'tiered':
                $pricing['tiers'] = $config["{$resourceType}_tiers"] ?? [];
                break;

            case 'base_addon':
                $pricing['included_units'] = (float) ($config["{$resourceType}_included"] ?? 0);
                $pricing['overage_rate'] = (float) ($config["{$resourceType}_overage"] ?? 0);
                break;
        }

        return $pricing;
    }

    private function createLocationOption(int $productId, array $locations): ConfigOption
    {
        $existingLocation = $this->findExistingOption($productId, 'location');

        if ($existingLocation) {
            $locationOption = $existingLocation;
        } else {
            $locationOption = ConfigOption::create([
                'name' => 'Location',
                'type' => 'select',
                'env_variable' => 'LOCATION',
                'hidden' => false,
                'sort' => 0,
                'parent_id' => null,
            ]);

            $locationOption->products()->syncWithoutDetaching([$productId]);
        }

        foreach ($locations as $loc) {
            $locationName = $loc['long'] ?: $loc['short'];
            ConfigOption::updateOrCreate([
                'parent_id' => $locationOption->id,
                'env_variable' => (string) $loc['id'],
            ], [
                'name' => $locationName,
                'type' => 'option',
                'hidden' => false,
                'sort' => 0,
            ]);
        }

        return $locationOption;
    }

    public function checkExistingOptions(int $productId): array
    {
        $resourceTypes = ['memory', 'cpu', 'disk'];

        $existing = DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $productId)
            ->where('config_options.type', 'dynamic_slider')
            ->whereNull('config_options.parent_id')
            ->get(['config_options.id', 'config_options.name', 'config_options.metadata']);

        $existingTypes = [];
        foreach ($existing as $option) {
            $metadata = json_decode($option->metadata, true);
            $resourceType = $metadata['resource_type'] ?? strtolower($option->name);
            if (in_array($resourceType, $resourceTypes)) {
                $existingTypes[$resourceType] = $option->id;
            }
        }

        return [
            'has_existing' => count($existingTypes) > 0,
            'existing_types' => $existingTypes,
            'existing_count' => count($existingTypes),
        ];
    }

    private function findExistingOption(int $productId, string $name): ?ConfigOption
    {
        $result = DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $productId)
            ->where(function ($query) use ($name) {
                $query->whereRaw('LOWER(config_options.name) = ?', [strtolower($name)])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(config_options.metadata, '$.resource_type')) = ?", [$name]);
            })
            ->whereNull('config_options.parent_id')
            ->first();

        return $result ? ConfigOption::find($result->id) : null;
    }

    public static function getProductsWithSlidersCount(): int
    {
        return DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_options.type', 'dynamic_slider')
            ->whereNull('config_options.parent_id')
            ->distinct('config_option_products.product_id')
            ->count('config_option_products.product_id');
    }
}
