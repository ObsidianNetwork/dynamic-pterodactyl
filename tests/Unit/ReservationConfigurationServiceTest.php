<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use PHPUnit\Framework\TestCase;

class ReservationConfigurationServiceTest extends TestCase
{
    public function test_fingerprint_is_stable_for_equivalent_key_order(): void
    {
        $service = new ReservationConfigurationService;

        $left = [
            'product_id' => 10,
            'resources' => ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
            'node_id' => 3,
        ];
        $right = [
            'node_id' => 3,
            'resources' => ['disk' => 51200, 'cpu' => 200, 'memory' => 4096],
            'product_id' => 10,
        ];

        $this->assertSame($service->fingerprint($left), $service->fingerprint($right));
    }

    public function test_resource_or_node_change_changes_fingerprint(): void
    {
        $service = new ReservationConfigurationService;
        $snapshot = [
            'customer_id' => 30,
            'cart_id' => 1,
            'server_extension_id' => 5,
            'panel_identity' => str_repeat('c', 64),
            'product_id' => 10,
            'plan_id' => 20,
            'quantity' => 1,
            'currency_code' => 'AUD',
            'location_id' => 2,
            'resources' => ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
            'calculated_price' => 25.00,
            'pricing_version' => str_repeat('a', 64),
            'formula_version' => ReservationConfigurationService::FORMULA_VERSION,
            'config_options' => [],
        ];

        $original = $service->fingerprint($service->withNode($snapshot, 3));

        $moreMemory = $snapshot;
        $moreMemory['resources']['memory'] = 8192;

        $this->assertNotSame(
            $original,
            $service->fingerprint($service->withNode($moreMemory, 3))
        );
        $this->assertNotSame(
            $original,
            $service->fingerprint($service->withNode($snapshot, 4))
        );

        $guestPayload = $service->withNode(array_replace($snapshot, ['customer_id' => null]), 3);
        $this->assertNotSame(
            $service->fingerprint($guestPayload),
            $service->fingerprint($service->withCustomer($guestPayload, 30))
        );
    }
}
