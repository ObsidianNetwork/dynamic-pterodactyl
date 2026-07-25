<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AllocationSelectionService;
use PHPUnit\Framework\TestCase;

class AllocationSelectionServiceTest extends TestCase
{
    public function test_dedicated_claim_uses_one_otherwise_unused_ip(): void
    {
        $selected = (new AllocationSelectionService())->select([
            [
                'id' => 1,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'ip_in_use' => true,
            ],
            [
                'id' => 2,
                'ip' => '192.0.2.10',
                'port' => 25566,
                'ip_in_use' => false,
            ],
            [
                'id' => 3,
                'ip' => '192.0.2.11',
                'port' => 25565,
                'ip_in_use' => false,
            ],
            [
                'id' => 4,
                'ip' => '192.0.2.11',
                'port' => 25566,
                'ip_in_use' => false,
            ],
        ], 2, dedicatedIp: true);

        $this->assertSame([3, 4], array_column($selected, 'id'));
    }

    public function test_equivalent_ipv6_spellings_share_one_dedicated_ip_group(): void
    {
        $selected = (new AllocationSelectionService())->select([
            [
                'id' => 10,
                'ip' => '2001:db8::1',
                'port' => 25565,
                'ip_in_use' => false,
            ],
            [
                'id' => 11,
                'ip' => '2001:0db8:0:0:0:0:0:1',
                'port' => 25566,
                'ip_in_use' => false,
            ],
        ], 2, dedicatedIp: true);

        $this->assertSame([10, 11], array_column($selected, 'id'));
    }

    public function test_port_range_constrains_primary_but_not_preclaimed_extras(): void
    {
        $selected = (new AllocationSelectionService())->select([
            [
                'id' => 20,
                'ip' => '192.0.2.20',
                'port' => 20000,
                'ip_in_use' => false,
            ],
            [
                'id' => 21,
                'ip' => '192.0.2.20',
                'port' => 25565,
                'ip_in_use' => false,
            ],
        ], 2, allowedPortRanges: [
            ['from' => 25560, 'to' => 25570],
        ]);

        $this->assertSame([21, 20], array_column($selected, 'id'));
    }

    public function test_dedicated_fixed_ports_must_exist_on_the_same_ip(): void
    {
        $selected = (new AllocationSelectionService())->select([
            [
                'id' => 30,
                'ip' => '192.0.2.30',
                'port' => 25565,
                'ip_in_use' => false,
            ],
            [
                'id' => 31,
                'ip' => '192.0.2.31',
                'port' => 25566,
                'ip_in_use' => false,
            ],
        ], 2, [25565, 25566], dedicatedIp: true);

        $this->assertNull($selected);
    }

    public function test_repeated_port_across_ips_uses_lowest_allocation_id(): void
    {
        $selected = (new AllocationSelectionService())->select([
            [
                'id' => 101,
                'ip' => '192.0.2.11',
                'port' => 25570,
            ],
            [
                'id' => 100,
                'ip' => '192.0.2.10',
                'port' => 25570,
            ],
        ], 1, [25570]);

        $this->assertSame([100], array_column($selected, 'id'));
    }

    public function test_dedicated_repeated_port_uses_lowest_eligible_ip_group(): void
    {
        $selected = (new AllocationSelectionService())->select([
            [
                'id' => 202,
                'ip' => '192.0.2.22',
                'port' => 25570,
                'ip_in_use' => false,
            ],
            [
                'id' => 201,
                'ip' => '192.0.2.21',
                'port' => 25570,
                'ip_in_use' => false,
            ],
        ], 1, [25570], dedicatedIp: true);

        $this->assertSame([201], array_column($selected, 'id'));
    }
}
