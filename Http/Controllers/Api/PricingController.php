<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\SliderConfigReaderService;

class PricingController
{
    private SliderConfigReaderService $sliderConfigReader;

    public function __construct(SliderConfigReaderService $sliderConfigReader)
    {
        $this->sliderConfigReader = $sliderConfigReader;
    }

    /**
     * Calculate price for given resource configuration
     */
    public function calculate(Request $request): JsonResponse
    {
        // Phase 1: validate product_id (static)
        $request->validate(['product_id' => 'required|integer|exists:products,id']);

        $product = Product::query()->with(['configOptions', 'plans'])->findOrFail($request->integer('product_id'));

        // Determine which sliders are configured for this product
        $sliderOptions = $product->configOptions
            ->where('type', 'dynamic_slider')
            ->whereNull('parent_id');

        if ($sliderOptions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'This product is not configured for dynamic pricing',
            ], 404);
        }

        // Phase 2: validate plan + only the configured slider fields
        $rules = ['plan_id' => 'nullable|integer|exists:plans,id'];
        foreach ($sliderOptions as $option) {
            $resourceType = $option->getMetadata('resource_type', strtolower($option->name));
            $rules[$resourceType] = 'required|integer|min:1';
        }

        $validated = array_merge(
            ['product_id' => $product->id],
            $request->validate($rules)
        );

        try {
            try {
                $plan = $this->resolvePlan($product, $validated['plan_id'] ?? null);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            $breakdown = [];
            $total = 0.0;
            $hasSliderInScope = false;

            foreach ($sliderOptions as $option) {
                $resourceType = $option->getMetadata('resource_type', strtolower($option->name));
                $value = (float) ($validated[$resourceType] ?? 0);

                if ($value <= 0) {
                    continue;
                }

                $hasSliderInScope = true;
                $price = $option->calculateDynamicPriceDelta($value, $plan->billing_period, $plan->billing_unit);

                $breakdown[] = [
                    'resource_type' => $resourceType,
                    'label' => $option->name,
                    'value' => $value,
                    'display_value' => $option->formatValueForDisplay($value),
                    'price' => round($price, 2),
                    'pricing_model' => $option->getMetadata('pricing.model', 'linear'),
                ];

                $total += $price;
            }

            if ($hasSliderInScope) {
                $total += $plan->dynamicSliderBasePrice();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => round($total, 2),
                    'breakdown' => $breakdown,
                    'model' => $sliderOptions->first()?->getMetadata('pricing.model', 'linear') ?? 'linear',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DynamicPterodactyl price calculation failed', [
                'product_id' => $validated['product_id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $payload = [
                'success' => false,
                'message' => 'Price calculation failed',
            ];

            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
            }

            return response()->json($payload, 500);
        }
    }

    /**
     * Get slider configuration for a product (reads from native ConfigOptions)
     */
    public function getConfig(int $productId): JsonResponse
    {
        $config = $this->sliderConfigReader->getConfig($productId);

        if (! $config['has_config']) {
            return response()->json([
                'success' => false,
                'message' => 'No dynamic slider config options found for this product',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $productId,
                'sliders' => $config['sliders'],
            ],
        ]);
    }

    private function resolvePlan(Product $product, ?int $planId): Plan
    {
        if ($planId !== null) {
            return $product->plans->firstWhere('id', $planId)
                ?? throw new \InvalidArgumentException('Selected plan does not belong to this product');
        }

        return $product->plans->sortBy('sort')->first()
            ?? throw new \InvalidArgumentException('No plans found for this product');
    }
}
