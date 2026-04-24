<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\CapacityAlertNotification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\ReservationShortfallNotification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AlertService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;
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

    private function invokeSendNotifications(AlertService $service, object $config, array $availability, array $alerts): void
    {
        $method = new \ReflectionMethod($service, 'sendNotifications');
        $method->setAccessible(true);
        $method->invoke($service, $config, $availability, $alerts);
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

    public function test_capacity_alert_email_fans_out_to_all_admins(): void
    {
        $recipientA = new class
        {
            public int $id = 101;

            public array $notifications = [];

            public function notify($notification): void
            {
                $this->notifications[] = $notification;
            }
        };

        $recipientB = new class
        {
            public int $id = 202;

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
        $this->invokeSendNotifications(
            $service,
            (object) [
                'id' => 55,
                'location_id' => 9,
                'location_name' => 'AMS-1',
                'email_notifications' => true,
                'notification_emails' => json_encode(['ops@example.com']),
                'webhook_notifications' => false,
                'webhook_url' => null,
            ],
            [
                'total_capacity' => ['memory' => 65536, 'disk' => 512000],
                'total_allocated' => ['memory' => 60000, 'disk' => 500000],
            ],
            [
                ['type' => 'critical', 'resource' => 'memory', 'utilization' => 91.5, 'usage_percent' => 91.5, 'threshold' => 90],
            ],
        );

        $this->assertCount(1, $recipientA->notifications);
        $this->assertCount(1, $recipientB->notifications);
        $this->assertInstanceOf(CapacityAlertNotification::class, $recipientA->notifications[0]);
        $this->assertSame('AMS-1', $recipientA->notifications[0]->alertConfig->location_name);
    }

    public function test_capacity_alert_email_logs_warning_when_no_admins(): void
    {
        Log::spy();

        $query = Mockery::mock();
        $query->shouldReceive('get')->once()->andReturn(new Collection());

        $user = Mockery::mock('alias:App\\Models\\User');
        $user->shouldReceive('whereNotNull')->once()->with('role_id')->andReturn($query);

        $service = $this->makeService();
        $this->invokeSendNotifications(
            $service,
            (object) [
                'id' => 99,
                'location_id' => null,
                'location_name' => null,
                'email_notifications' => true,
                'notification_emails' => json_encode(['ops@example.com']),
                'webhook_notifications' => false,
                'webhook_url' => null,
            ],
            [
                'location_id' => 99,
                'location_name' => 'Test Scope',
                'total_capacity' => ['memory' => 100, 'disk' => 100],
                'total_allocated' => ['memory' => 80, 'disk' => 80],
            ],
            [['type' => 'warning', 'resource' => 'disk', 'utilization' => 80.0, 'usage_percent' => 80.0, 'threshold' => 80]],
        );

        Log::shouldHaveReceived('warning')->once()->with(
            'No admin recipients configured for capacity alert',
            Mockery::on(fn (array $context) => $context['alert_config_id'] === 99)
        );
    }

    public function test_capacity_alert_email_uses_evaluated_location_for_global_scope(): void
    {
        $recipient = new class
        {
            public int $id = 303;

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
        $this->invokeSendNotifications(
            $service,
            (object) [
                'id' => 100,
                'location_id' => null,
                'location_name' => null,
                'email_notifications' => true,
                'notification_emails' => json_encode(['ops@example.com']),
                'webhook_notifications' => false,
                'webhook_url' => null,
            ],
            [
                'location_id' => 44,
                'location_name' => 'FRA-1',
                'total_capacity' => ['memory' => 65536, 'disk' => 512000],
                'total_allocated' => ['memory' => 60000, 'disk' => 500000],
            ],
            [
                ['type' => 'critical', 'resource' => 'memory', 'utilization' => 91.5, 'usage_percent' => 91.5, 'threshold' => 90],
            ],
        );

        $this->assertCount(1, $recipient->notifications);
        $this->assertSame(44, $recipient->notifications[0]->alertConfig->location_id);
        $this->assertSame('FRA-1', $recipient->notifications[0]->alertConfig->location_name);
    }

    public function test_capacity_alert_email_logged_on_dispatch_failure(): void
    {
        Log::spy();

        $failingRecipient = new class
        {
            public int $id = 7;

            public function notify($notification): void
            {
                throw new \RuntimeException('smtp down');
            }
        };

        $healthyRecipient = new class
        {
            public int $id = 8;

            public array $notifications = [];

            public function notify($notification): void
            {
                $this->notifications[] = $notification;
            }
        };

        $query = Mockery::mock();
        $query->shouldReceive('get')->once()->andReturn(new Collection([$failingRecipient, $healthyRecipient]));

        $user = Mockery::mock('alias:App\\Models\\User');
        $user->shouldReceive('whereNotNull')->once()->with('role_id')->andReturn($query);

        $service = $this->makeService();
        $this->invokeSendNotifications(
            $service,
            (object) [
                'id' => 77,
                'location_id' => 1,
                'location_name' => 'DFW-1',
                'email_notifications' => true,
                'notification_emails' => json_encode(['ops@example.com']),
                'webhook_notifications' => false,
                'webhook_url' => null,
            ],
            [],
            [['type' => 'critical', 'resource' => 'memory', 'utilization' => 97.2, 'usage_percent' => 97.2, 'threshold' => 95]],
        );

        $this->assertCount(1, $healthyRecipient->notifications);
        $this->assertInstanceOf(CapacityAlertNotification::class, $healthyRecipient->notifications[0]);
        Log::shouldHaveReceived('warning')->with(
            'Failed to send capacity alert email',
            Mockery::on(fn (array $context) => $context['alert_config_id'] === 77
                && $context['recipient_id'] === 7
                && $context['error'] === 'smtp down')
        );
    }

}

class AlertServiceAuditTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private function makeService(ResourceCalculationService $resourceService): AlertService
    {
        return new AlertService($resourceService);
    }

    private function runCheck(AlertService $service, AlertConfig $alertConfig): void
    {
        $method = new \ReflectionMethod($service, 'checkAlertConfig');
        $method->setAccessible(true);
        $method->invoke($service, $alertConfig);
    }

    public function test_capacity_alert_writes_audit_row_on_successful_send(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role_id' => 1]);
        $alertConfig = AlertConfig::create([
            'location_id' => 12,
            'location_name' => 'HEL-1',
            'memory_warning_threshold' => 80,
            'memory_critical_threshold' => 90,
            'disk_warning_threshold' => 80,
            'disk_critical_threshold' => 90,
            'email_notifications' => true,
            'notification_emails' => ['ops@example.com'],
            'webhook_notifications' => false,
            'cooldown_minutes' => 60,
            'is_active' => true,
        ]);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocationAvailability')->once()->with(12)->andReturn([
            'location_id' => 12,
            'total_capacity' => ['memory' => 100, 'disk' => 100],
            'total_allocated' => ['memory' => 95, 'disk' => 50],
        ]);

        $service = $this->makeService($resourceService);
        $this->runCheck($service, $alertConfig);

        Notification::assertSentTo($admin, CapacityAlertNotification::class);
        $this->assertDatabaseHas('ptero_audit_logs', [
            'action' => 'capacity_alert_sent',
            'entity_type' => 'alert_config',
            'entity_id' => $alertConfig->id,
        ]);

        $log = DB::table('ptero_audit_logs')->where('action', 'capacity_alert_sent')->latest('id')->first();
        $newValues = json_decode($log->new_values, true);

        $this->assertSame(['email'], $newValues['channels']);
        $this->assertSame('critical', $newValues['severity']);
        $this->assertSame(['memory'], $newValues['breached']);
    }

    public function test_capacity_alert_audit_is_best_effort(): void
    {
        Notification::fake();
        Log::spy();

        $admin = User::factory()->create(['role_id' => 1]);
        $alertConfig = AlertConfig::create([
            'location_id' => 18,
            'location_name' => 'IAD-1',
            'memory_warning_threshold' => 80,
            'memory_critical_threshold' => 90,
            'disk_warning_threshold' => 80,
            'disk_critical_threshold' => 90,
            'email_notifications' => true,
            'notification_emails' => ['ops@example.com'],
            'webhook_notifications' => false,
            'cooldown_minutes' => 60,
            'is_active' => true,
        ]);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocationAvailability')->once()->with(18)->andReturn([
            'location_id' => 18,
            'total_capacity' => ['memory' => 100, 'disk' => 100],
            'total_allocated' => ['memory' => 91, 'disk' => 50],
        ]);

        $auditLogService = Mockery::mock(AuditLogService::class);
        $auditLogService->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogService::class, $auditLogService);

        $service = $this->makeService($resourceService);
        $this->runCheck($service, $alertConfig);

        Notification::assertSentTo($admin, CapacityAlertNotification::class);
        Log::shouldHaveReceived('warning')->with(
            'extension audit write failed',
            Mockery::on(fn (array $context) => $context['action'] === 'capacity_alert_sent'
                && $context['entity_type'] === 'alert_config'
                && $context['entity_id'] === $alertConfig->id
                && $context['error'] === 'audit unavailable')
        );
    }

    public function test_capacity_alert_uses_evaluated_location_scope_in_audit_for_global_config(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role_id' => 1]);
        $alertConfig = AlertConfig::create([
            'location_id' => null,
            'location_name' => null,
            'memory_warning_threshold' => 80,
            'memory_critical_threshold' => 90,
            'disk_warning_threshold' => 80,
            'disk_critical_threshold' => 90,
            'email_notifications' => true,
            'notification_emails' => ['ops@example.com'],
            'webhook_notifications' => false,
            'cooldown_minutes' => 60,
            'is_active' => true,
        ]);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocations')->once()->andReturn([
            ['id' => 44, 'name' => 'FRA-1'],
        ]);
        $resourceService->shouldReceive('getLocationAvailability')->once()->with(44)->andReturn([
            'location_id' => 44,
            'location_name' => 'FRA-1',
            'total_capacity' => ['memory' => 100, 'disk' => 100],
            'total_allocated' => ['memory' => 95, 'disk' => 50],
        ]);

        $service = $this->makeService($resourceService);
        $this->runCheck($service, $alertConfig);

        Notification::assertSentTo($admin, CapacityAlertNotification::class, function (CapacityAlertNotification $notification) {
            return $notification->alertConfig->location_id === 44
                && $notification->alertConfig->location_name === 'FRA-1';
        });

        $log = DB::table('ptero_audit_logs')->where('action', 'capacity_alert_sent')->latest('id')->first();
        $newValues = json_decode($log->new_values, true);

        $this->assertSame(44, $newValues['location_scope']);
    }

    public function test_capacity_alert_does_not_audit_failed_webhook_delivery(): void
    {
        Notification::fake();
        Http::fake([
            '*' => Http::response(['message' => 'forbidden'], 403),
        ]);
        Log::spy();

        $alertConfig = AlertConfig::create([
            'location_id' => 23,
            'location_name' => 'MAD-1',
            'memory_warning_threshold' => 80,
            'memory_critical_threshold' => 90,
            'disk_warning_threshold' => 80,
            'disk_critical_threshold' => 90,
            'email_notifications' => false,
            'notification_emails' => [],
            'webhook_notifications' => true,
            'webhook_url' => 'https://discord.com/api/webhooks/SECRET_TOKEN',
            'cooldown_minutes' => 60,
            'is_active' => true,
        ]);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocationAvailability')->once()->with(23)->andReturn([
            'location_id' => 23,
            'location_name' => 'MAD-1',
            'total_capacity' => ['memory' => 100, 'disk' => 100],
            'total_allocated' => ['memory' => 95, 'disk' => 50],
        ]);

        $service = $this->makeService($resourceService);
        $this->runCheck($service, $alertConfig);

        $this->assertDatabaseMissing('ptero_audit_logs', [
            'action' => 'capacity_alert_sent',
            'entity_type' => 'alert_config',
            'entity_id' => $alertConfig->id,
        ]);
        Log::shouldHaveReceived('error')->with(
            'Webhook notification failed',
            Mockery::on(fn (array $context) => $context['alert_config_id'] === $alertConfig->id
                && $context['webhook_host'] === 'discord.com')
        );
    }
}
