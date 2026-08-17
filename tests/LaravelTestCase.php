<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class LaravelTestCase extends BaseTestCase
{
    /**
     * Creates the application.
     */
    public function createApplication()
    {
        $paymenterBasePath = getenv('PAYMENTER_BASE_PATH');
        $bootstrap = $paymenterBasePath
            ? rtrim($paymenterBasePath, '/\\') . '/bootstrap/app.php'
            : __DIR__ . '/../../../../bootstrap/app.php';

        if (!file_exists($bootstrap)) {
            $bootstrap = '/var/www/paymenter/bootstrap/app.php';
        }

        if (!file_exists($bootstrap)) {
            throw new \RuntimeException('Unable to locate Paymenter bootstrap/app.php. Set PAYMENTER_BASE_PATH for standalone checkouts.');
        }

        $app = require $bootstrap;
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Create a mock ConfigOption object for dynamic_slider type
     */
    protected function createConfigOption(
        string $resourceType,
        string $pricingModel,
        array $pricingConfig,
        array $sliderConfig = []
    ): object {
        $defaults = $this->getResourceDefaults($resourceType);
        $slider = array_merge($defaults, $sliderConfig);

        return new class($resourceType, $pricingModel, $pricingConfig, $slider)
        {
            public int $id;

            public string $name;

            public string $type = 'dynamic_slider';

            public array $metadata;

            public function __construct(string $resourceType, string $pricingModel, array $pricingConfig, array $slider)
            {
                $this->id = rand(1, 1000);
                $this->name = ucfirst($resourceType);
                $this->metadata = [
                    'resource_type' => $resourceType,
                    'min' => $slider['min'],
                    'max' => $slider['max'],
                    'step' => $slider['step'],
                    'default' => $slider['default'],
                    'unit' => $slider['unit'],
                    'display_unit' => $slider['display_unit'],
                    'display_divisor' => $slider['display_divisor'],
                    'pricing' => array_merge(['model' => $pricingModel], $pricingConfig),
                ];
            }

            public function calculateDynamicPrice(float $value, int $quantity, string $cycle): float
            {
                $pricing = $this->metadata['pricing'];
                $model = $pricing['model'] ?? 'linear';

                // Convert to display units for calculation
                $divisor = $this->metadata['display_divisor'] ?? 1;
                $displayValue = $value / $divisor;

                switch ($model) {
                    case 'linear':
                        $rate = $pricing['rate_per_unit'] ?? 0;

                        return $displayValue * $rate * $quantity;

                    case 'tiered':
                        return $this->calculateTieredPrice($displayValue, $pricing['tiers'] ?? [], $quantity);

                    case 'base_addon':
                        $included = $pricing['included_units'] ?? 0;
                        $overage = max(0, $displayValue - $included);
                        $overageRate = $pricing['overage_rate'] ?? 0;

                        return $overage * $overageRate * $quantity;

                    default:
                        return 0;
                }
            }

            private function calculateTieredPrice(float $value, array $tiers, int $quantity): float
            {
                if (empty($tiers)) {
                    return 0;
                }

                $total = 0;
                $remaining = $value;
                $previousLimit = 0;

                foreach ($tiers as $tier) {
                    $limit = $tier['up_to'] ?? PHP_INT_MAX;
                    $rate = $tier['rate'] ?? 0;
                    $tierAmount = min($remaining, $limit - $previousLimit);

                    if ($tierAmount > 0) {
                        $total += $tierAmount * $rate;
                        $remaining -= $tierAmount;
                    }

                    $previousLimit = $limit;
                    if ($remaining <= 0) {
                        break;
                    }
                }

                return $total * $quantity;
            }
        };
    }

    /**
     * Get default slider config for a resource type
     */
    protected function getResourceDefaults(string $resourceType): array
    {
        return match ($resourceType) {
            'memory' => [
                'min' => 1024, 'max' => 65536, 'step' => 1024, 'default' => 4096,
                'unit' => 'MB', 'display_unit' => 'GB', 'display_divisor' => 1024,
            ],
            'cpu' => [
                'min' => 100, 'max' => 800, 'step' => 100, 'default' => 200,
                'unit' => '%', 'display_unit' => 'cores', 'display_divisor' => 100,
            ],
            'disk' => [
                'min' => 10240, 'max' => 512000, 'step' => 10240, 'default' => 51200,
                'unit' => 'MB', 'display_unit' => 'GB', 'display_divisor' => 1024,
            ],
            default => [
                'min' => 0, 'max' => 100, 'step' => 1, 'default' => 0,
                'unit' => '', 'display_unit' => '', 'display_divisor' => 1,
            ],
        };
    }

    /**
     * Create a mock node data array
     */
    protected function createNodeData(
        int $nodeId,
        string $name,
        array $total,
        array $available,
        bool $maintenance = false
    ): array {
        return [
            'node_id' => $nodeId,
            'name' => $name,
            'maintenance_mode' => $maintenance,
            'total' => $total,
            'available' => $available,
        ];
    }

    /**
     * Standard resource requirements for testing
     */
    protected function standardResources(): array
    {
        return [
            'memory' => 4096,  // 4GB
            'cpu' => 200,      // 2 cores
            'disk' => 51200,   // 50GB
        ];
    }
}
