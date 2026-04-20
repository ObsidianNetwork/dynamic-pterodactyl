<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\CartItem\Created;
use App\Models\ConfigOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class CartItemCreatedListener
{
    public function handle(Created $event): void
    {
        $cartItem = $event->cartItem;

        // Check if this product has dynamic_slider config options (Pterodactyl resources)
        if (! $this->hasDynamicSliderOptions($cartItem->product_id)) {
            return;
        }

        // Extract resource values from Paymenter's native config_options array
        // config_options format: [{option_id: X, value: Y}, ...]
        $resources = $this->extractResourcesFromConfigOptions(
            $cartItem->config_options ?? []
        );

        // Check if required resources exist (at minimum, need memory to proceed)
        if (empty($resources)) {
            Log::debug('CartItem has no dynamic_slider resources for reservation', [
                'cart_item_id' => $cartItem->id,
            ]);

            return;
        }

        // Location is required for reservation - check checkout_config or config_options
        $locationId = $this->extractLocationId($cartItem);
        if (! $locationId) {
            Log::debug('CartItem missing location for reservation', [
                'cart_item_id' => $cartItem->id,
            ]);

            return;
        }

        try {
            $reservationService = app(ReservationService::class);

            $reservation = $reservationService->create(
                productId: $cartItem->product_id,
                locationId: (int) $locationId,
                resources: [
                    'memory' => (int) ($resources['memory'] ?? 0),
                    'cpu' => (int) ($resources['cpu'] ?? 0),
                    'disk' => (int) ($resources['disk'] ?? 0),
                ],
                cartItemId: $cartItem->id,
                userId: auth()->id()
            );

            // Store reservation token in checkout_config for later reference
            $checkoutConfig = $cartItem->checkout_config ?? [];
            $checkoutConfig['_reservation_token'] = $reservation['token'];
            $checkoutConfig['_selected_node'] = $reservation['node_id'];
            $checkoutConfig['_calculated_price'] = $reservation['pricing']['total'];

            $cartItem->update([
                'checkout_config' => $checkoutConfig,
            ]);

            Log::info('Created resource reservation for cart item', [
                'cart_item_id' => $cartItem->id,
                'reservation_token' => substr($reservation['token'], 0, 8) . '...',
                'node_id' => $reservation['node_id'],
                'expires_at' => $reservation['expires_at'],
            ]);

        } catch (\Exception $e) {
            // Log error but don't block the cart operation
            // User can still checkout, but without guaranteed resources
            Log::error('Failed to create resource reservation', [
                'cart_item_id' => $cartItem->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract resource values from native dynamic_slider config options
     * Uses metadata.resource_type to identify memory/cpu/disk sliders
     */
    private function extractResourcesFromConfigOptions(array $configOptions): array
    {
        $resources = [];

        foreach ($configOptions as $opt) {
            $optionId = $opt['option_id'] ?? null;
            $value = $opt['value'] ?? null;

            if (! $optionId || $value === null) {
                continue;
            }

            // Get the config option to check its type and metadata
            $configOption = ConfigOption::find($optionId);

            if (! $configOption || $configOption->type !== 'dynamic_slider') {
                continue;
            }

            // Get resource type from metadata (memory, cpu, disk, custom)
            $resourceType = $configOption->getMetadata('resource_type');

            if ($resourceType && in_array($resourceType, ['memory', 'cpu', 'disk'])) {
                $resources[$resourceType] = $value;
            }
        }

        return $resources;
    }

    /**
     * Extract location ID from cart item (checkout_config or config_options)
     */
    private function extractLocationId($cartItem): ?int
    {
        // First, check checkout_config (extension-provided location selector)
        $checkoutConfig = $cartItem->checkout_config ?? [];
        if (isset($checkoutConfig['location'])) {
            return (int) $checkoutConfig['location'];
        }

        // Fall back to config_options (if location is a select/radio type)
        foreach ($cartItem->config_options ?? [] as $opt) {
            $optionId = $opt['option_id'] ?? null;
            $value = $opt['value'] ?? null;

            if (! $optionId) {
                continue;
            }

            $configOption = ConfigOption::find($optionId);
            if ($configOption && strtolower($configOption->name) === 'location') {
                // Value might be a child config option ID - get its env_variable (Ptero location ID)
                if (is_numeric($value)) {
                    $pteroLocationId = DB::table('config_options')
                        ->where('id', $value)
                        ->value('env_variable');

                    return $pteroLocationId ? (int) $pteroLocationId : (int) $value;
                }

                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Check if a product has any dynamic_slider config options
     */
    private function hasDynamicSliderOptions(int $productId): bool
    {
        return DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $productId)
            ->where('config_options.type', 'dynamic_slider')
            ->whereNull('config_options.parent_id')
            ->exists();
    }
}
