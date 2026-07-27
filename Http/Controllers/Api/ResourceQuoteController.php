<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use App\Classes\Cart;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidResourceSelectionException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\StockUnavailableException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Requests\ResourceQuoteRequest;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceQuoteService;

class ResourceQuoteController
{
    public function __construct(private readonly ResourceQuoteService $quotes) {}

    public function __invoke(ResourceQuoteRequest $request, int $product): JsonResponse
    {
        $productModel = Product::query()
            ->whereKey($product)
            ->where('hidden', false)
            ->first();

        if ($productModel === null) {
            return response()->json([
                'message' => 'The requested product is not available.',
            ], 404);
        }
        $productModel->loadMissing([
            'plans.prices',
            'server',
            'configOptions',
        ]);

        if (
            $productModel->stock === 0
            || ! $productModel->price()->available
            || $productModel->server?->extension !== 'Pterodactyl'
            || ! $productModel->usesDynamicResources()
        ) {
            return response()->json([
                'message' => 'The requested product is not available.',
            ], 404);
        }

        try {
            return response()->json([
                'data' => $this->quotes->quote(
                    $productModel,
                    $request->validated('config_options'),
                    $this->excludedReservationToken(
                        $productModel,
                        $request->validated('cart_item_id')
                    )
                ),
            ]);
        } catch (InvalidResourceSelectionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (StockUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (InvalidStockConfigurationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dynamic stock is not configured for this product.',
            ], 503);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dynamic stock is temporarily unavailable.',
            ], 503);
        }
    }

    private function excludedReservationToken(
        Product $product,
        mixed $cartItemId
    ): ?string {
        if ($cartItemId === null) {
            return null;
        }

        $cart = Cart::getOnce();
        $item = $cart->exists
            ? $cart->items()
                ->whereKey((int) $cartItemId)
                ->where('product_id', $product->id)
                ->first()
            : null;

        if ($item === null) {
            throw new InvalidResourceSelectionException(
                'The cart item is not available for this resource quote.'
            );
        }

        return ResourceReservation::query()
            // Stock accounting keeps an expired pending row authoritative
            // until the cart mutation atomically retires it and releases its
            // allocation claims. Let the owning cart exclude that same row
            // while re-quoting so it can reach the cleanup/replacement
            // transaction instead of deadlocking behind its own stale hold.
            ->where('status', ResourceReservation::STATUS_PENDING)
            ->where('cart_item_id', $item->id)
            ->value('token');
    }
}
