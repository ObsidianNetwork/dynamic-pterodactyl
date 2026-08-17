<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
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
}
