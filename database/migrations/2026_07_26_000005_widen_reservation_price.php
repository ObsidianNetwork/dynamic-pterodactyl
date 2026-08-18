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
            // Match Paymenter's invoice-item precision so the immutable quote
            // cannot overflow this extension's older DECIMAL(10,2) snapshot.
            $table->decimal('calculated_price', 17, 2)->change();
        });
    }

    public function down(): void
    {
        if (
            (float) DB::table('ptero_resource_reservations')
                ->max('calculated_price') > 99_999_999.99
        ) {
            throw new RuntimeException(
                'Reservation prices no longer fit the legacy DECIMAL(10,2) column.'
            );
        }

        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            $table->decimal('calculated_price', 10, 2)->change();
        });
    }
};
