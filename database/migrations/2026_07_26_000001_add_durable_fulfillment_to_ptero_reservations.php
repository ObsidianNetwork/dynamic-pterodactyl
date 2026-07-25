<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        $statuses = [
            'pending',
            'paid_committed',
            'confirmed',
            'expired',
            'cancelled',
        ];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE ptero_resource_reservations MODIFY status "
                . "ENUM('pending','paid_committed','confirmed','expired','cancelled') "
                . "NOT NULL DEFAULT 'pending'"
            );
        } elseif ($driver === 'sqlite') {
            // Laravel represents SQLite enums as CHECK-constrained text.
            // Rebuild the column so paid_committed is valid on an existing
            // installation as well as a fresh schema.
            Schema::table(
                'ptero_resource_reservations',
                fn (Blueprint $table) => $table
                    ->enum('status', $statuses)
                    ->default('pending')
                    ->change()
            );
        }

        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable()->after('service_id');
            $table->timestamp('guaranteed_until')->nullable()->after('expires_at');
            $table->timestamp('paid_committed_at')->nullable()->after('guaranteed_until');
            $table->unsignedInteger('provisioning_attempts')->default(0)->after('provisioning_started_at');
            $table->timestamp('last_provisioning_attempt_at')->nullable()->after('provisioning_attempts');
            $table->timestamp('next_provisioning_attempt_at')->nullable()->after('last_provisioning_attempt_at');
            $table->timestamp('failure_alerted_at')->nullable()->after('last_provisioning_error');
            $table->timestamp('cancellation_requested_at')->nullable()->after('failure_alerted_at');
            $table->text('last_cancellation_error')->nullable()->after('cancellation_requested_at');
            $table->timestamp('cancellation_failure_alerted_at')->nullable()->after('last_cancellation_error');
            $table->unsignedBigInteger('external_server_id')->nullable()->after('cancellation_failure_alerted_at');
            $table->unsignedBigInteger('external_user_id')->nullable()->after('external_server_id');
            $table->uuid('external_server_uuid')->nullable()->after('external_user_id');
            $table->string('external_server_identifier', 64)->nullable()->after('external_server_uuid');
            $table->timestamp('last_reconciled_at')->nullable()->after('external_server_identifier');
            $table->timestamp('customer_notified_at')->nullable()->after('last_reconciled_at');
            $table->timestamp('product_stock_released_at')->nullable()->after('customer_notified_at');

            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->nullOnDelete();
            $table->index(['invoice_id', 'status'], 'ptero_reservations_invoice_status_idx');
            $table->index(
                ['status', 'next_provisioning_attempt_at'],
                'ptero_reservations_retry_idx'
            );
        });

        DB::table('ptero_resource_reservations')
            ->whereNull('guaranteed_until')
            ->update(['guaranteed_until' => DB::raw('expires_at')]);

        // Legacy terminal reservations were already handled by Paymenter's
        // pre-durable cancellation paths. Mark them released so a repeated
        // cancellation cannot return the same product unit twice.
        DB::table('ptero_resource_reservations')
            ->whereIn('status', ['expired', 'cancelled'])
            ->whereNull('product_stock_released_at')
            ->update(['product_stock_released_at' => now()]);

        Schema::create('ptero_capacity_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('panel_identity', 64);
            $table->unsignedInteger('location_id');
            $table->timestamps();

            $table->unique(
                ['panel_identity', 'location_id'],
                'ptero_capacity_scopes_panel_location_unique'
            );
        });

        Schema::create('ptero_reservation_allocations', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->foreignId('reservation_id')
                ->constrained('ptero_resource_reservations')
                ->cascadeOnDelete();
            $table->string('panel_identity', 64);
            $table->unsignedInteger('node_id');
            $table->unsignedBigInteger('allocation_id');
            $table->string('ip', 64)->nullable();
            $table->unsignedInteger('port');
            $table->string('environment_key', 191)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            if ($driver !== 'sqlite') {
                $table->unsignedBigInteger('active_allocation_id')
                    ->nullable()
                    ->storedAs('CASE WHEN released_at IS NULL THEN allocation_id ELSE NULL END');
                $table->unique(
                    ['panel_identity', 'active_allocation_id'],
                    'ptero_reservation_allocations_active_unique'
                );
            }
            $table->index(
                ['reservation_id', 'released_at'],
                'ptero_reservation_allocations_reservation_idx'
            );
            $table->index(
                ['node_id', 'released_at'],
                'ptero_reservation_allocations_node_idx'
            );
        });

        if ($driver === 'sqlite') {
            // Partial indexes provide the same one-active-claim invariant
            // without requiring SQLite generated-column support.
            DB::statement(
                'CREATE UNIQUE INDEX ptero_reservation_allocations_active_unique '
                .'ON ptero_reservation_allocations (panel_identity, allocation_id) '
                .'WHERE released_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_reservation_allocations');
        Schema::dropIfExists('ptero_capacity_scopes');

        DB::table('ptero_resource_reservations')
            ->where('status', 'paid_committed')
            ->update(['status' => 'pending']);

        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropIndex('ptero_reservations_invoice_status_idx');
            $table->dropIndex('ptero_reservations_retry_idx');
            $table->dropColumn([
                'invoice_id',
                'guaranteed_until',
                'paid_committed_at',
                'provisioning_attempts',
                'last_provisioning_attempt_at',
                'next_provisioning_attempt_at',
                'failure_alerted_at',
                'cancellation_requested_at',
                'last_cancellation_error',
                'cancellation_failure_alerted_at',
                'external_server_id',
                'external_user_id',
                'external_server_uuid',
                'external_server_identifier',
                'last_reconciled_at',
                'customer_notified_at',
                'product_stock_released_at',
            ]);
        });

        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE ptero_resource_reservations MODIFY status "
                . "ENUM('pending','confirmed','expired','cancelled') "
                . "NOT NULL DEFAULT 'pending'"
            );
        } elseif ($driver === 'sqlite') {
            Schema::table(
                'ptero_resource_reservations',
                fn (Blueprint $table) => $table
                    ->enum('status', [
                        'pending',
                        'confirmed',
                        'expired',
                        'cancelled',
                    ])
                    ->default('pending')
                    ->change()
            );
        }
    }
};
