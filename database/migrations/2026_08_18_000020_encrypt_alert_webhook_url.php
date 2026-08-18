<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ptero_alert_configs')
            || ! Schema::hasColumn('ptero_alert_configs', 'webhook_url')) {
            return;
        }

        Schema::table('ptero_alert_configs', function (Blueprint $table): void {
            $table->text('webhook_url')->nullable()->change();
        });

        DB::table('ptero_alert_configs')
            ->whereNotNull('webhook_url')
            ->where('webhook_url', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($configs): void {
                foreach ($configs as $config) {
                    $webhookUrl = (string) $config->webhook_url;

                    try {
                        Crypt::decryptString($webhookUrl);
                        $encrypted = $webhookUrl;
                    } catch (DecryptException) {
                        $encrypted = Crypt::encryptString($webhookUrl);
                    }

                    if ($encrypted !== $webhookUrl) {
                        DB::table('ptero_alert_configs')
                            ->where('id', $config->id)
                            ->update(['webhook_url' => $encrypted]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: rollback must not restore webhook credentials to plaintext.
    }
};
