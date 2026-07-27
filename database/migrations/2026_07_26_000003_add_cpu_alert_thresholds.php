<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ptero_alert_configs')) {
            return;
        }

        $addWarning = ! Schema::hasColumn(
            'ptero_alert_configs',
            'cpu_warning_threshold'
        );
        $addCritical = ! Schema::hasColumn(
            'ptero_alert_configs',
            'cpu_critical_threshold'
        );
        Schema::table(
            'ptero_alert_configs',
            function (Blueprint $table) use ($addWarning, $addCritical): void {
                if ($addWarning) {
                    $table->unsignedTinyInteger('cpu_warning_threshold')
                        ->default(80);
                }
                if ($addCritical) {
                    $table->unsignedTinyInteger('cpu_critical_threshold')
                        ->default(95);
                }
            }
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('ptero_alert_configs')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn(
                'ptero_alert_configs',
                'cpu_warning_threshold'
            ) ? 'cpu_warning_threshold' : null,
            Schema::hasColumn(
                'ptero_alert_configs',
                'cpu_critical_threshold'
            ) ? 'cpu_critical_threshold' : null,
        ]));
        Schema::table(
            'ptero_alert_configs',
            function (Blueprint $table) use ($columns): void {
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            }
        );
    }
};
