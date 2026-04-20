<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\ConfigOption;
use Illuminate\Support\Facades\DB;

class PricingCalculatorService
{
    /**
     * Calculate price for given resources using native ConfigOptions
     *
     * @param  int  $productId  The product ID
     * @param  array  $resources  Array with 'memory' (MB), 'cpu' (%), 'disk' (MB)
     * @return array Pricing breakdown with total and per-resource details
     */
    public function calculate(int $productId, array $resources): array
    {
        $options = $this->getDynamicSliderOptions($productId);

        if ($options->isEmpty()) {
            return [
                'total' => 0,
                'breakdown' => [],
                'model' => 'none',
                'message' => 'No dynamic slider config options found for this product',
            ];
        }

        $breakdown = [];
        $total = 0;

        foreach ($options as $option) {
            $metadata = $option->metadata;
            if (! is_array($metadata)) {
                $metadata = json_decode($metadata, true) ?? [];
            }

            $resourceType = $metadata['resource_type'] ?? strtolower($option->name);
            $value = $resources[$resourceType] ?? 0;

            if ($value <= 0) {
                continue;
            }

            // Calculate price using ConfigOption model method
            $price = $option->calculateDynamicPrice($value, 1, 'month');

            // Format value for display
            $displayValue = $this->formatResourceValue($resourceType, $value, $metadata);

            $breakdown[] = [
                'resource_type' => $resourceType,
                'label' => $option->name,
                'value' => $value,
                'display_value' => $displayValue,
                'price' => round($price, 2),
                'pricing_model' => $metadata['pricing']['model'] ?? 'linear',
            ];

            $total += $price;
        }

        // Determine the primary pricing model from the first option
        $primaryModel = $options->first()?->metadata['pricing']['model'] ?? 'linear';
        if (is_string($options->first()?->metadata)) {
            $meta = json_decode($options->first()->metadata, true);
            $primaryModel = $meta['pricing']['model'] ?? 'linear';
        }

        return [
            'total' => round($total, 2),
            'breakdown' => $breakdown,
            'model' => $primaryModel,
        ];
    }

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
     * Format a resource value for display
     */
    private function formatResourceValue(string $resourceType, float $value, array $metadata): string
    {
        $divisor = $metadata['display_divisor'] ?? 1;
        $displayUnit = $metadata['display_unit'] ?? $metadata['unit'] ?? '';

        $displayValue = $value / $divisor;

        // Format nicely
        if ($displayValue == (int) $displayValue) {
            return (int) $displayValue . ' ' . $displayUnit;
        }

        return number_format($displayValue, 1) . ' ' . $displayUnit;
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
