<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\ReservationResource;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ReservationStatisticsTest extends LaravelTestCase
{
    use DatabaseTransactions;

    public function test_confirmed_revenue_is_never_summed_across_currencies(): void
    {
        $this->reservation('USD', '10.10', 'confirmed');
        $this->reservation('USD', '2.20', 'confirmed');
        $this->reservation('AUD', '7.00', 'confirmed');
        $this->reservation(null, '1.00', 'confirmed');
        $this->reservation('USD', '100.00', 'pending');
        $old = $this->reservation('USD', '50.00', 'confirmed');
        $old->forceFill([
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ])->save();

        $stats = app(ReservationService::class)->getStatistics('30d');

        $this->assertSame([
            'AUD' => '7.00',
            'UNSPECIFIED' => '1.00',
            'USD' => '12.30',
        ], $stats['confirmed_revenue_by_currency']);
        $this->assertArrayNotHasKey('confirmed_revenue', $stats);
    }

    public function test_reservation_table_formats_each_rows_own_currency(): void
    {
        $formatter = new \ReflectionMethod(
            ReservationResource::class,
            'formatPrice'
        );
        $formatter->setAccessible(true);

        $this->assertSame(
            'AUD 12.30',
            $formatter->invoke(null, '12.30', 'aud')
        );
        $this->assertSame(
            '12.30 (currency unavailable)',
            $formatter->invoke(null, '12.30', null)
        );
    }

    private function reservation(
        ?string $currency,
        string $price,
        string $status
    ): ResourceReservation {
        return ResourceReservation::query()->create([
            'token' => Str::random(64),
            'node_id' => 1,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
            'currency_code' => $currency,
            'calculated_price' => $price,
            'pricing_breakdown' => [],
            'status' => $status,
            'expires_at' => now()->addDay(),
        ]);
    }
}
