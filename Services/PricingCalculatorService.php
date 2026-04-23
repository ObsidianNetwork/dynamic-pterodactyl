<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\ConfigOption;

class PricingCalculatorService
{
    /**
     * Get pricing configuration for a product (for API/frontend consumption)
     *
     * @return array Slider configurations with pricing info
     */
    public function getConfig(int $productId): array
    {
        $options = $this->getDynamicSliderOptions($productId);

        if ($options->isEmpty()) {
            return [
                'has_config' => false,
                'sliders' => [],
            ];
        }

        $sliders = [];

        foreach ($options as $option) {
            $metadata = $option->metadata;
            if (! is_array($metadata)) {
                $metadata = json_decode($metadata, true) ?? [];
            }

            $resourceType = $metadata['resource_type'] ?? strtolower($option->name);

            $sliders[$resourceType] = [
                'config_option_id' => $option->id,
                'name' => $option->name,
                'min' => $metadata['min'] ?? 0,
                'max' => $metadata['max'] ?? 0,
                'step' => $metadata['step'] ?? 1,
                'default' => $metadata['default'] ?? $metadata['min'] ?? 0,
                'unit' => $metadata['unit'] ?? '',
                'display_unit' => $metadata['display_unit'] ?? $metadata['unit'] ?? '',
                'display_divisor' => $metadata['display_divisor'] ?? 1,
                'pricing' => $metadata['pricing'] ?? ['model' => 'linear', 'rate_per_unit' => 0],
            ];
        }

        return [
            'has_config' => true,
            'sliders' => $sliders,
        ];
    }

    /**
     * Get dynamic_slider ConfigOptions for a product
     *
     * @return \Illuminate\Support\Collection
     */
    private function getDynamicSliderOptions(int $productId)
    {
        return ConfigOption::whereHas('products', fn ($q) => $q->where('product_id', $productId))
            ->where('type', 'dynamic_slider')
            ->whereNull('parent_id')
            ->get();
    }

    /**
     * Validate that resources are within configured limits
     *
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateResources(int $productId, array $resources): array
    {
        $config = $this->getConfig($productId);

        if (! $config['has_config']) {
            return ['valid' => false, 'errors' => ['No slider configuration found']];
        }

        $errors = [];

        foreach ($config['sliders'] as $resourceType => $slider) {
            $value = $resources[$resourceType] ?? null;

            if ($value === null) {
                continue;
            }

            if ($value < $slider['min']) {
                $errors[] = ucfirst($resourceType) . " must be at least {$slider['min']} {$slider['unit']}";
            }

            if ($value > $slider['max']) {
                $errors[] = ucfirst($resourceType) . " cannot exceed {$slider['max']} {$slider['unit']}";
            }

            // Check step alignment
            if ($slider['step'] > 0 && ($value - $slider['min']) % $slider['step'] !== 0) {
                $errors[] = ucfirst($resourceType) . " must be in increments of {$slider['step']} {$slider['unit']}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
