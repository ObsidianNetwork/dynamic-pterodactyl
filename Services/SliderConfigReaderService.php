<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\ConfigOption;

class SliderConfigReaderService
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

}
