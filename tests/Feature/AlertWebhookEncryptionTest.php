<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Events\AlertDeliveryFailed;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AlertService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\WebhookEndpointPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AlertWebhookEncryptionTest extends LaravelTestCase
{
    public function test_webhook_url_is_encrypted_at_rest(): void
    {
        $webhookUrl = 'https://hooks.example.com/path?token=storage-secret';
        $config = AlertConfig::create(['webhook_url' => $webhookUrl]);

        $stored = DB::table('ptero_alert_configs')
            ->where('id', $config->id)
            ->value('webhook_url');

        $this->assertIsString($stored);
        $this->assertNotSame($webhookUrl, $stored);
        $this->assertStringNotContainsString('storage-secret', $stored);
        $this->assertSame($webhookUrl, $config->fresh()->webhook_url);
    }

    public function test_migration_encrypts_existing_plaintext_without_double_encryption(): void
    {
        $webhookUrl = 'https://hooks.example.com/path?token=legacy-secret';
        $configId = DB::table('ptero_alert_configs')->insertGetId([
            'webhook_url' => $webhookUrl,
        ]);
        $migration = require dirname(__DIR__, 2)
            .'/database/migrations/2026_08_18_000020_encrypt_alert_webhook_url.php';

        $migration->up();
        $encrypted = DB::table('ptero_alert_configs')
            ->where('id', $configId)
            ->value('webhook_url');
        $migration->up();

        $this->assertIsString($encrypted);
        $this->assertNotSame($webhookUrl, $encrypted);
        $this->assertSame(
            $encrypted,
            DB::table('ptero_alert_configs')->where('id', $configId)->value('webhook_url')
        );
        $this->assertSame($webhookUrl, AlertConfig::findOrFail($configId)->webhook_url);
    }

    public function test_scheduler_decrypts_persisted_webhook_url_before_delivery(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response([], 204)]);

        $webhookUrl = 'https://hooks.example.com/capacity?token=stored-secret';
        $alertConfig = AlertConfig::create([
            'location_id' => 13,
            'location_name' => 'SYD-1',
            'memory_warning_threshold' => 80,
            'memory_critical_threshold' => 90,
            'disk_warning_threshold' => 80,
            'disk_critical_threshold' => 90,
            'email_notifications' => false,
            'notification_emails' => [],
            'webhook_notifications' => true,
            'webhook_url' => $webhookUrl,
            'cooldown_minutes' => 60,
            'is_active' => true,
        ]);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocationAvailability')->once()->with(13)->andReturn([
            'location_id' => 13,
            'location_name' => 'SYD-1',
            'total_capacity' => ['memory' => 100, 'disk' => 100],
            'total_allocated' => ['memory' => 95, 'disk' => 50],
        ]);
        $service = new AlertService(
            $resourceService,
            new WebhookEndpointPolicy(
                static fn (string $host): array => ['93.184.216.34']
            )
        );

        $this->assertSame(1, $service->checkCapacityAlerts());
        Http::assertSent(
            fn ($request): bool => $request->url() === $webhookUrl
        );
        $this->assertNotNull($alertConfig->fresh()->last_notification_at);
        Event::assertNotDispatched(AlertDeliveryFailed::class);
    }
}
