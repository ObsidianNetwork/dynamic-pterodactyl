<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            // MariaDB does not allow a generated column to depend on
            // cart_item_id because its foreign key uses ON DELETE SET NULL.
            // Keep an immutable non-FK copy solely for the partial-unique guard.
            $table->unsignedBigInteger('cart_item_guard_id')->nullable()->after('cart_item_id');
            $table->unsignedBigInteger('cart_id')->nullable()->after('cart_item_id');
            $table->unsignedBigInteger('server_extension_id')->nullable()->after('cart_id');
            $table->string('panel_identity', 64)->nullable()->after('server_extension_id');
            $table->unsignedBigInteger('product_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('plan_id')->nullable()->after('product_id');
            $table->unsignedInteger('quantity')->default(1)->after('plan_id');
            $table->string('currency_code', 3)->nullable()->after('quantity');
            $table->string('configuration_fingerprint', 64)->nullable()->after('currency_code');
            $table->json('configuration_payload')->nullable()->after('configuration_fingerprint');
            $table->string('pricing_version', 64)->nullable()->after('configuration_payload');
            $table->string('formula_version', 64)->nullable()->after('pricing_version');
            $table->timestamp('provisioning_started_at')->nullable()->after('expires_at');
            $table->string('provisioning_lease_id', 64)->nullable()->after('provisioning_started_at');
            $table->timestamp('consumed_at')->nullable()->after('provisioning_lease_id');
            $table->text('last_provisioning_error')->nullable()->after('consumed_at');

            $table->unsignedBigInteger('active_cart_item_id')
                ->nullable()
                ->storedAs("CASE WHEN status = 'pending' THEN cart_item_guard_id ELSE NULL END")
                ->after('cart_item_guard_id');
        });

        // Every pre-migration pending row came from one of the two superseded
        // token flows and lacks the immutable identity needed for safe use.
        // Active carts will acquire a fresh hold on edit or checkout.
        DB::table('ptero_resource_reservations')
            ->where('status', 'pending')
            ->whereNull('configuration_fingerprint')
            ->update([
                'status' => 'cancelled',
                'admin_notes' => 'Retired during migration to server-owned reservations.',
                'updated_at' => now(),
            ]);

        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            $table->unique('active_cart_item_id', 'ptero_reservations_active_cart_item_unique');
            // Legacy releases could attach both browser and listener holds to one
            // service. Keep this non-unique so the migration is deployable; the
            // new bind path locks the cart hold and prevents new duplicates.
            $table->index('service_id', 'ptero_reservations_service_idx');
            $table->index(['cart_id', 'status'], 'ptero_reservations_cart_status_idx');
            $table->index('server_extension_id', 'ptero_reservations_server_extension_idx');
            $table->index('configuration_fingerprint', 'ptero_reservations_fingerprint_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            $table->dropUnique('ptero_reservations_active_cart_item_unique');
            $table->dropIndex('ptero_reservations_service_idx');
            $table->dropIndex('ptero_reservations_cart_status_idx');
            $table->dropIndex('ptero_reservations_server_extension_idx');
            $table->dropIndex('ptero_reservations_fingerprint_idx');
            $table->dropColumn([
                'active_cart_item_id',
                'cart_item_guard_id',
                'cart_id',
                'server_extension_id',
                'panel_identity',
                'product_id',
                'plan_id',
                'quantity',
                'currency_code',
                'configuration_fingerprint',
                'configuration_payload',
                'pricing_version',
                'formula_version',
                'provisioning_started_at',
                'provisioning_lease_id',
                'consumed_at',
                'last_provisioning_error',
            ]);
        });
    }
};
