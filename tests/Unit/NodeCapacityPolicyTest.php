<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Paymenter\Extensions\Others\DynamicPterodactyl\Models\NodeCapacityPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\TestCase;

class NodeCapacityPolicyTest extends TestCase
{
    public function test_effective_capacity_applies_basis_point_overcommit(): void
    {
        $policy = new NodeCapacityPolicy([
            'cpu_capacity_percent' => 800,
            'cpu_overcommit_bps' => 15000,
            'enabled' => true,
        ]);

        $this->assertSame(1200, $policy->effectiveCpuCapacity());
    }

    public function test_policy_bounds_prevent_overflowing_capacity_math(): void
    {
        $policy = new NodeCapacityPolicy([
            'cpu_capacity_percent' => NodeCapacityPolicy::MAX_CPU_CAPACITY_PERCENT + 1,
            'cpu_overcommit_bps' => 10000,
            'enabled' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $policy->effectiveCpuCapacity();
    }
}
