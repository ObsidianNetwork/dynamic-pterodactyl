<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\Product;
use App\Models\Service;
use App\Support\PanelEndpointIdentity;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use PHPUnit\Framework\TestCase;

class ReservationConfigurationServiceTest extends TestCase
{
    public function test_checkout_panel_normalization_matches_shared_identity_without_path_collision(): void
    {
        $normalizer = new \ReflectionMethod(
            ReservationConfigurationService::class,
            'normalizePanelUrl'
        );
        $normalizer->setAccessible(true);
        $service = new ReservationConfigurationService;
        $canonical = $normalizer->invoke(
            $service,
            'HTTPS://Panel.Example.com:443/PanelA/'
        );
        $differentPath = $normalizer->invoke(
            $service,
            'https://panel.example.com/panela'
        );

        $this->assertSame(
            PanelEndpointIdentity::canonicalUrl(
                'https://panel.example.com/PanelA'
            ),
            $canonical
        );
        $this->assertNotSame($canonical, $differentPath);
        $this->assertSame(
            '',
            $normalizer->invoke(
                $service,
                'https://panel.example.com/PanelA?token=secret'
            )
        );
    }

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
            $service->fingerprint($service->withCustomer(
                $guestPayload,
                30,
                'Customer@Example.com'
            ))
        );
    }

    public function test_bound_service_uses_snapshot_when_product_defaults_change(): void
    {
        $configuration = new ReservationConfigurationService;
        $payload = [
            'customer_id' => 30,
            'cart_id' => 1,
            'server_extension_id' => 5,
            'panel_identity' => str_repeat('c', 64),
            'product_id' => 10,
            'plan_id' => 20,
            'quantity' => 1,
            'currency_code' => 'AUD',
            'location_id' => 2,
            'node_id' => 3,
            'resources' => [
                'memory' => 4096,
                'cpu' => 200,
                'disk' => 51200,
            ],
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' => 'paymenter-user-30',
                'user_email' => 'customer@example.com',
            ],
            'config_options' => [],
            'allocations' => [[
                'allocation_id' => 91,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]],
        ];
        $service = new Service;
        $service->setRawAttributes([
            'id' => 40,
            'user_id' => 30,
            'product_id' => 10,
            'plan_id' => 20,
            'quantity' => 1,
            'currency_code' => 'AUD',
        ], true);
        $service->exists = true;
        $service->setRelation('user', new \App\Models\User([
            'id' => 30,
            'email' => 'renamed@example.com',
        ]));
        // These mutable defaults deliberately disagree with the hold. They
        // must not reprice or invalidate the already-promised capacity.
        $service->setRelation('product', new Product([
            'server_id' => 999,
            'memory' => 65536,
            'cpu' => 900,
            'disk' => 999999,
        ]));
        $reservation = (object) [
            'service_id' => 40,
            'user_id' => 30,
            'server_extension_id' => 5,
            'panel_identity' => str_repeat('c', 64),
            'product_id' => 10,
            'plan_id' => 20,
            'quantity' => 1,
            'currency_code' => 'AUD',
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'location_id' => 2,
            'node_id' => 3,
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'configuration_fingerprint' => $configuration->fingerprint($payload),
        ];

        $configuration->assertServiceMatches($service, $reservation);

        $this->addToAssertionCount(1);
    }
}
