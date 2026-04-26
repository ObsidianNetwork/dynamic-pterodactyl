<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Requests;

use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cartItemId = $this->integer('cart_item_id');

        if (! $cartItemId) {
            return true;
        }

        $cartItem = CartItem::query()
            ->with('cart')
            ->find($cartItemId);

        if (! $cartItem || ! $cartItem->cart) {
            return false;
        }

        return $cartItem->cart->user_id === $this->user()?->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key', $this->input('idempotency_key')),
        ]);
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer|exists:products,id',
            'location_id' => 'required|integer',
            'memory' => 'sometimes|integer|min:0',
            'cpu' => 'sometimes|integer|min:0',
            'disk' => 'sometimes|integer|min:0',
            'cart_item_id' => 'nullable|integer|exists:cart_items,id',
            'idempotency_key' => ['nullable', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $productId = (int) $this->input('product_id');

            if ($productId <= 0 || ! Product::query()->whereKey($productId)->exists()) {
                return;
            }

            $allowedLocationIds = $this->getAllowedLocationIds($productId);
            $locationId = (int) $this->input('location_id');
            if ($allowedLocationIds !== [] && ! in_array($locationId, $allowedLocationIds, true)) {
                $validator->errors()->add('location_id', 'The selected location is not configured for this product');
            }

            $sliders = $this->getDynamicSliderConfig($productId);

            if ($sliders === []) {
                $validator->errors()->add('product_id', 'This product is not configured for dynamic reservations');

                return;
            }

            $providedResources = collect(['memory', 'cpu', 'disk'])
                ->filter(fn (string $resource) => $this->exists($resource))
                ->values()
                ->all();

            foreach (array_diff(array_keys($sliders), $providedResources) as $resource) {
                $validator->errors()->add($resource, ucfirst($resource) . ' is required for this product');
            }

            foreach (array_diff($providedResources, array_keys($sliders)) as $resource) {
                $validator->errors()->add($resource, ucfirst($resource) . ' is not configured for this product');
            }

            foreach ($sliders as $resource => $slider) {
                if (! $this->exists($resource) || $validator->errors()->has($resource)) {
                    continue;
                }

                $value = $this->input($resource);
                if (! is_numeric($value)) {
                    continue;
                }

                $value = (int) $value;
                $min = (int) ($slider['min'] ?? 0);
                $max = (int) ($slider['max'] ?? 0);
                $step = max(1, (int) ($slider['step'] ?? 1));

                if ($value < $min || $value > $max) {
                    $validator->errors()->add($resource, ucfirst($resource) . " must be between {$min} and {$max}");

                    continue;
                }

                if (($value - $min) % $step !== 0) {
                    $validator->errors()->add($resource, ucfirst($resource) . " must increase in steps of {$step}");
                }
            }
        });
    }

    private function getDynamicSliderConfig(int $productId): array
    {
        return DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $productId)
            ->where('config_options.type', 'dynamic_slider')
            ->whereNull('config_options.parent_id')
            ->select(['config_options.name', 'config_options.metadata'])
            ->get()
            ->mapWithKeys(function ($option) {
                $metadata = is_array($option->metadata)
                    ? $option->metadata
                    : json_decode($option->metadata ?? '[]', true) ?? [];

                $resourceType = $metadata['resource_type'] ?? strtolower($option->name);

                if (! in_array($resourceType, ['memory', 'cpu', 'disk'], true)) {
                    return [];
                }

                return [$resourceType => $metadata];
            })
            ->all();
    }

    private function getAllowedLocationIds(int $productId): array
    {
        $product = Product::query()->find($productId);
        $setting = $product?->settings()
            ->where('key', 'location_ids')
            ->value('value');

        if ($setting === null || $setting === '') {
            return [];
        }

        $locationIds = is_array($setting) ? $setting : json_decode($setting, true);
        if (! is_array($locationIds)) {
            return [];
        }

        return collect($locationIds)
            ->filter(fn ($locationId) => is_numeric($locationId))
            ->map(fn ($locationId) => (int) $locationId)
            ->values()
            ->all();
    }
}
