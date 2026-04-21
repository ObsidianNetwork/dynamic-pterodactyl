<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Events\Invoice\Paid;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\InvoicePaidListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AlertService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class InvoicePaidListenerTest extends LaravelTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_shortfall_triggers_notification(): void
    {
        $token = 'shortfall-token';
        $reservation = (object) [
            'id' => 50,
            'node_id' => 7,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ];

        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('getByToken')->once()->with($token)->andReturn($reservation);
        $this->app->instance(ReservationService::class, $reservationService);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('verifyAvailability')->once()->with(7, [
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ])->andReturn(false);
        $this->app->instance(ResourceCalculationService::class, $resourceService);

        $alertService = Mockery::mock(AlertService::class);
        $alertService->shouldReceive('notifyShortfall')->once()->with(
            10,
            20,
            ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
            'insufficient_resources'
        );
        $this->app->instance(AlertService::class, $alertService);

        $listener = new InvoicePaidListener;
        $listener->handle(new Paid($this->makeInvoiceEventData(serviceId: 10, invoiceId: 20, token: $token)));

        $this->addToAssertionCount(1);
    }

    public function test_state_drift_triggers_notification(): void
    {
        $token = 'state-drift-token';
        $reservation = (object) [
            'id' => 51,
            'node_id' => 8,
            'memory' => 2048,
            'cpu' => 150,
            'disk' => 25600,
        ];
        $current = (object) ['status' => 'expired'];

        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('getByToken')->once()->with($token)->andReturn($reservation);
        $reservationService->shouldReceive('confirm')->once()->with($token, 11)->andReturn(false);
        $reservationService->shouldReceive('getByToken')->once()->with($token)->andReturn($current);
        $this->app->instance(ReservationService::class, $reservationService);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('verifyAvailability')->once()->with(8, [
            'memory' => 2048,
            'cpu' => 150,
            'disk' => 25600,
        ])->andReturn(true);
        $this->app->instance(ResourceCalculationService::class, $resourceService);

        $alertService = Mockery::mock(AlertService::class);
        $alertService->shouldReceive('notifyShortfall')->once()->with(
            11,
            21,
            ['memory' => 2048, 'cpu' => 150, 'disk' => 25600],
            'state_drift:expired'
        );
        $this->app->instance(AlertService::class, $alertService);

        $listener = new InvoicePaidListener;
        $listener->handle(new Paid($this->makeInvoiceEventData(serviceId: 11, invoiceId: 21, token: $token)));

        $this->addToAssertionCount(1);
    }

    private function makeInvoiceEventData(int $serviceId, int $invoiceId, string $token): Invoice
    {
        $propertyQuery = Mockery::mock();
        $propertyQuery->shouldReceive('where')->once()->with('key', '_reservation_token')->andReturnSelf();
        $propertyQuery->shouldReceive('value')->once()->with('value')->andReturn($token);

        $service = new class($propertyQuery) extends Service
        {
            public function __construct(private object $propertyQuery) {}

            public function properties()
            {
                return $this->propertyQuery;
            }
        };
        $service->id = $serviceId;

        $item = new InvoiceItem;
        $item->reference_type = Service::class;
        $item->setRelation('reference', $service);

        $invoice = new Invoice;
        $invoice->id = $invoiceId;
        $invoice->setRelation('items', collect([$item]));

        return $invoice;
    }
}
