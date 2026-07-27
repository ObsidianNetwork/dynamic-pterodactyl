<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Controllers\Api;

use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\StockUnavailableException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\UpgradeReservationService;

class UpgradeQuoteController
{
    public function __construct(
        private readonly UpgradeReservationService $upgrades
    ) {}

    public function __invoke(
        Request $request,
        Service $service
    ): JsonResponse {
        Gate::authorize('view', $service);
        $validated = $request->validate([
            'config_options' => ['required', 'array'],
        ]);

        try {
            return response()->json([
                'data' => $this->upgrades->quoteForService(
                    $service,
                    $validated['config_options']
                ),
            ]);
        } catch (\InvalidArgumentException $exception) {
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
                'message' => 'Dynamic upgrade stock is not configured safely.',
            ], 503);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dynamic upgrade stock is temporarily unavailable.',
            ], 503);
        }
    }
}
