<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Http\Requests\StoreReservationRequest;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class StoreReservationRequestTest extends LaravelTestCase
{
    use DatabaseTransactions;

    /**
     * @dataProvider reservationPayloadProvider
     */
    public function test_store_reservation_request_validation(
        array $sliders,
        array $payload,
        bool $shouldPass,
        string $errorField = '',
    ): void {
        $product = $this->createProductWithSliders($sliders);
        $payload['product_id'] = $product->id;
        $payload['location_id'] ??= 1;

        $request = StoreReservationRequest::createFromBase(Request::create(
            '/api/dynamic-pterodactyl/reservation',
            'POST',
            $payload,
        ));

        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        if ($shouldPass) {
            $request->validateResolved();
            $this->addToAssertionCount(1);

            return;
        }

        try {
            $request->validateResolved();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorField, $exception->errors());
        }
    }

    public static function reservationPayloadProvider(): array
    {
        return [
            'valid request passes' => [
                'sliders' => [
                    'memory' => ['min' => 1024, 'max' => 8192, 'step' => 1024, 'unit' => 'MB'],
                    'cpu' => ['min' => 100, 'max' => 400, 'step' => 100, 'unit' => '%'],
                    'disk' => ['min' => 10240, 'max' => 102400, 'step' => 10240, 'unit' => 'MB'],
                ],
                'payload' => ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
                'shouldPass' => true,
            ],
            'out of bounds memory rejected' => [
                'sliders' => [
                    'memory' => ['min' => 1024, 'max' => 8192, 'step' => 1024, 'unit' => 'MB'],
                    'cpu' => ['min' => 100, 'max' => 400, 'step' => 100, 'unit' => '%'],
                    'disk' => ['min' => 10240, 'max' => 102400, 'step' => 10240, 'unit' => 'MB'],
                ],
                'payload' => ['memory' => 99999, 'cpu' => 200, 'disk' => 51200],
                'shouldPass' => false,
                'errorField' => 'memory',
            ],
            'wrong step rejected' => [
                'sliders' => [
                    'memory' => ['min' => 1024, 'max' => 8192, 'step' => 1024, 'unit' => 'MB'],
                    'cpu' => ['min' => 100, 'max' => 400, 'step' => 100, 'unit' => '%'],
                    'disk' => ['min' => 10240, 'max' => 102400, 'step' => 10240, 'unit' => 'MB'],
                ],
                'payload' => ['memory' => 1536, 'cpu' => 200, 'disk' => 51200],
                'shouldPass' => false,
                'errorField' => 'memory',
            ],
            'missing required resource rejected' => [
                'sliders' => [
                    'memory' => ['min' => 1024, 'max' => 8192, 'step' => 1024, 'unit' => 'MB'],
                    'cpu' => ['min' => 100, 'max' => 400, 'step' => 100, 'unit' => '%'],
                ],
                'payload' => ['memory' => 4096],
                'shouldPass' => false,
                'errorField' => 'cpu',
            ],
            'extra resource rejected' => [
                'sliders' => [
                    'memory' => ['min' => 1024, 'max' => 8192, 'step' => 1024, 'unit' => 'MB'],
                    'cpu' => ['min' => 100, 'max' => 400, 'step' => 100, 'unit' => '%'],
                ],
                'payload' => ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
                'shouldPass' => false,
                'errorField' => 'disk',
            ],
            'product without slider config rejected' => [
                'sliders' => [],
                'payload' => ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
                'shouldPass' => false,
                'errorField' => 'product_id',
            ],
        ];
    }

    private function createProductWithSliders(array $sliders): Product
    {
        /** @var Product $product */
        $product = Product::factory()->create();

        foreach ($sliders as $resourceType => $slider) {
            $optionId = DB::table('config_options')->insertGetId([
                'name' => ucfirst($resourceType),
                'type' => 'dynamic_slider',
                'sort' => 0,
                'hidden' => false,
                'upgradable' => true,
                'metadata' => json_encode([
                    'resource_type' => $resourceType,
                    'min' => $slider['min'],
                    'max' => $slider['max'],
                    'step' => $slider['step'],
                    'default' => $slider['min'],
                    'unit' => $slider['unit'],
                    'display_unit' => $slider['unit'],
                    'display_divisor' => 1,
                    'pricing' => ['model' => 'linear', 'rate_per_unit' => 1],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('config_option_products')->insert([
                'product_id' => $product->id,
                'config_option_id' => $optionId,
            ]);
        }

        return $product;
    }
}
