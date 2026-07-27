<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'ptero_resource_reservations',
            function (Blueprint $table): void {
                $table->dropForeign(['service_id']);
                $table->dropForeign(['user_id']);

                $table->foreign('service_id')
                    ->references('id')
                    ->on('services')
                    ->restrictOnDelete();
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            }
        );
        $this->restoreSqlitePartialIndexes();
    }

    public function down(): void
    {
        Schema::table(
            'ptero_resource_reservations',
            function (Blueprint $table): void {
                $table->dropForeign(['service_id']);
                $table->dropForeign(['user_id']);

                $table->foreign('service_id')
                    ->references('id')
                    ->on('services')
                    ->nullOnDelete();
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        );
        $this->restoreSqlitePartialIndexes();
    }

    /**
     * Laravel rebuilds SQLite tables to alter foreign keys. Recreate the two
     * conditional uniqueness guards because SQLite schema introspection does
     * not retain partial-index predicates during that rebuild.
     */
    private function restoreSqlitePartialIndexes(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement(
            'DROP INDEX IF EXISTS '
            .'ptero_reservations_active_upgrade_unique'
        );
        DB::statement(
            'CREATE UNIQUE INDEX ptero_reservations_active_upgrade_unique '
            .'ON ptero_resource_reservations (upgrade_guard_id) '
            ."WHERE purpose = 'upgrade' "
            ."AND status IN ('pending', 'paid_committed') "
            .'AND upgrade_guard_id IS NOT NULL'
        );
        DB::statement(
            'DROP INDEX IF EXISTS '
            .'ptero_reservations_active_checkout_service_unique'
        );
        DB::statement(
            'CREATE UNIQUE INDEX '
            .'ptero_reservations_active_checkout_service_unique '
            .'ON ptero_resource_reservations (service_guard_id) '
            ."WHERE purpose = 'checkout' "
            ."AND status IN ('pending', 'paid_committed', 'confirmed') "
            .'AND service_guard_id IS NOT NULL'
        );
    }
};
