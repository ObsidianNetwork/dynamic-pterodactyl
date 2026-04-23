<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PricingCalculatorService;

class PricingController
{
    private PricingCalculatorService $pricingService;

    public function __construct(PricingCalculatorService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * Calculate price for given resource configuration
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'plan_id' => 'nullable|integer|exists:plans,id',
            'memory' => 'required|integer|min:1',
            'cpu' => 'required|integer|min:1',
            'disk' => 'required|integer|min:1',
        ]);

        try {
            $product = Product::query()->with(['configOptions', 'plans'])->findOrFail($validated['product_id']);
            $plan = $this->resolvePlan($product, $validated['plan_id'] ?? null);
            $resources = [
                'memory' => $validated['memory'],
                'cpu' => $validated['cpu'],
                'disk' => $validated['disk'],
            ];

            $sliderOptions = $product->configOptions
                ->where('type', 'dynamic_slider')
                ->whereNull('parent_id');

            if ($sliderOptions->isEmpty()) {
                $pricing = [
                    'total' => 0,
                    'breakdown' => [],
                    'model' => 'none',
                    'message' => 'No dynamic slider config options found for this product',
                ];
            } else {
                $breakdown = [];
                $total = 0.0;
                $hasSliderInScope = false;

                foreach ($sliderOptions as $option) {
                    $resourceType = $option->getMetadata('resource_type', strtolower($option->name));
                    $value = (float) ($resources[$resourceType] ?? 0);

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

                $pricing = [
                    'total' => round($total, 2),
                    'breakdown' => $breakdown,
                    'model' => $sliderOptions->first()?->getMetadata('pricing.model', 'linear') ?? 'linear',
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $pricing,
            ]);
        } catch (\Exception $e) {
            Log::error('DynamicPterodactyl price calculation failed', [
                'product_id' => $validated['product_id'],
                'resources' => $validated,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Price calculation failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get slider configuration for a product (reads from native ConfigOptions)
     */
    public function getConfig(int $productId): JsonResponse
    {
        $config = $this->pricingService->getConfig($productId);

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

    /**
     * Validate resource values against configured limits
     */
    public function validate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'memory' => 'nullable|integer|min:0',
            'cpu' => 'nullable|integer|min:0',
            'disk' => 'nullable|integer|min:0',
        ]);

        $result = $this->pricingService->validateResources(
            $validated['product_id'],
            array_filter([
                'memory' => $validated['memory'] ?? null,
                'cpu' => $validated['cpu'] ?? null,
                'disk' => $validated['disk'] ?? null,
            ], fn ($v) => $v !== null)
        );

        return response()->json([
            'success' => $result['valid'],
            'errors' => $result['errors'],
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
