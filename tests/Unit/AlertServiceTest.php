<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\ReservationShortfallNotification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AlertService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AlertServiceTest extends TestCase
{
    private function makeService(): AlertService
    {
        $mockResource = Mockery::mock(ResourceCalculationService::class);

        return new AlertService($mockResource);
    }

    public function test_notify_shortfall_emails_all_admins(): void
    {
        $recipientA = new class
        {
            public array $notifications = [];

            public function notify($notification): void
            {
                $this->notifications[] = $notification;
            }
        };

        $recipientB = new class
        {
            public array $notifications = [];

            public function notify($notification): void
            {
                $this->notifications[] = $notification;
            }
        };

        $query = Mockery::mock();
        $query->shouldReceive('get')->once()->andReturn(new Collection([$recipientA, $recipientB]));

        $user = Mockery::mock('alias:App\\Models\\User');
        $user->shouldReceive('whereNotNull')->once()->with('role_id')->andReturn($query);

        $service = $this->makeService();
        $service->notifyShortfall(42, 100, ['memory' => 4096, 'cpu' => 200, 'disk' => 51200], 'insufficient_resources');

        $this->assertCount(1, $recipientA->notifications);
        $this->assertCount(1, $recipientB->notifications);
        $this->assertInstanceOf(ReservationShortfallNotification::class, $recipientA->notifications[0]);
        $this->assertSame('insufficient_resources', $recipientA->notifications[0]->reason);
        $this->assertSame(
            ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
            $recipientA->notifications[0]->reservationSnapshot
        );
    }

    public function test_notify_shortfall_reason_matches(): void
    {
        $recipient = new class
        {
            public array $notifications = [];

            public function notify($notification): void
            {
                $this->notifications[] = $notification;
            }
        };

        $query = Mockery::mock();
        $query->shouldReceive('get')->once()->andReturn(new Collection([$recipient]));

        $user = Mockery::mock('alias:App\\Models\\User');
        $user->shouldReceive('whereNotNull')->once()->with('role_id')->andReturn($query);

        $service = $this->makeService();
        $service->notifyShortfall(10, 20, ['memory' => 1024, 'cpu' => 100, 'disk' => 10240], 'state_drift:expired');

        $this->assertCount(1, $recipient->notifications);
        $this->assertSame('state_drift:expired', $recipient->notifications[0]->reason);
        $this->assertSame(10, $recipient->notifications[0]->serviceId);
        $this->assertSame(20, $recipient->notifications[0]->invoiceId);
        $this->assertSame(
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            $recipient->notifications[0]->reservationSnapshot
        );
    }

    public function test_notify_shortfall_no_admins_logs_warning(): void
    {
        Log::spy();

        $query = Mockery::mock();
        $query->shouldReceive('get')->once()->andReturn(new Collection());

        $user = Mockery::mock('alias:App\\Models\\User');
        $user->shouldReceive('whereNotNull')->once()->with('role_id')->andReturn($query);

        $service = $this->makeService();
        $service->notifyShortfall(1, 1, ['memory' => 0, 'cpu' => 0, 'disk' => 0], 'insufficient_resources');

        Log::shouldHaveReceived('warning')->once()->with(
            'No admin recipients configured for shortfall alert',
            Mockery::on(fn (array $context) => $context['service_id'] === 1 && $context['reason'] === 'insufficient_resources')
        );
    }
}
