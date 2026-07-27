<?php

use App\Models\Extension;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = DB::table('settings')
            ->join('extensions', function ($join) {
                $join->on('extensions.id', '=', 'settings.settingable_id')
                    ->where('settings.settingable_type', '=', Extension::class);
            })
            ->where('extensions.extension', 'DynamicPterodactyl')
            ->where('settings.key', 'pterodactyl_api_key')
            ->where('settings.encrypted', false)
            ->get(['settings.id', 'settings.value']);

        foreach ($settings as $setting) {
            DB::table('settings')
                ->where('id', $setting->id)
                ->update([
                    'value' => $setting->value === null || $setting->value === ''
                        ? $setting->value
                        : Crypt::encryptString((string) $setting->value),
                    'encrypted' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: a rollback must never restore an API key
        // to plaintext storage.
    }
};
