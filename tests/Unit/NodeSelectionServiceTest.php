<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class NodeSelectionServiceTest extends LaravelTestCase
{
    private NodeSelectionService $service;
    private $mockResourceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockResourceService = Mockery::mock(ResourceCalculationService::class);
        $this->service = new NodeSelectionService($this->mockResourceService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that node with most headroom is selected.
     */
    public function test_selects_node_with_most_headroom(): void
    {
        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'nodes' => [
                    $this->createNodeData(1, 'Node 1', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 8192,   // 50% free
                        'cpu' => 400,
                        'disk' => 51200,
                    ]),
                    $this->createNodeData(2, 'Node 2', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 12288,  // 75% free - more headroom
                        'cpu' => 600,
                        'disk' => 76800,
                    ]),
                ],
                'max_available' => [],
            ]);

        $result = $this->service->selectBestNode(1, [
            'memory' => 4096,
            'cpu' => 100,
            'disk' => 20480,
        ]);

        // Node 2 should be selected (more headroom)
        $this->assertNotNull($result);
        $this->assertEquals(2, $result['node_id']);
        $this->assertEquals('Node 2', $result['name']);
    }

    /**
     * Test that nodes in maintenance mode are skipped.
     */
    public function test_skips_nodes_in_maintenance(): void
    {
        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'nodes' => [
                    $this->createNodeData(1, 'Node 1 (Maint)', [
                        'memory' => 32768,
                        'cpu' => 1600,
                        'disk' => 204800,
                    ], [
                        'memory' => 32768,  // Best headroom but in maintenance
                        'cpu' => 1600,
                        'disk' => 204800,
                    ], true),  // maintenance = true
                    $this->createNodeData(2, 'Node 2', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 8192,
                        'cpu' => 400,
                        'disk' => 51200,
                    ]),
                ],
                'max_available' => [],
            ]);

        $result = $this->service->selectBestNode(1, [
            'memory' => 4096,
            'cpu' => 100,
            'disk' => 20480,
        ]);

        // Node 2 should be selected (Node 1 is in maintenance)
        $this->assertNotNull($result);
        $this->assertEquals(2, $result['node_id']);
    }

    /**
     * Test that nodes with insufficient memory are skipped.
     */
    public function test_skips_nodes_with_insufficient_memory(): void
    {
        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'nodes' => [
                    $this->createNodeData(1, 'Low Memory Node', [
                        'memory' => 8192,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 2048,   // Only 2GB free
                        'cpu' => 600,
                        'disk' => 80000,
                    ]),
                    $this->createNodeData(2, 'Good Node', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 8192,   // 8GB free
                        'cpu' => 400,
                        'disk' => 51200,
                    ]),
                ],
                'max_available' => [],
            ]);

        $result = $this->service->selectBestNode(1, [
            'memory' => 4096,  // Requires 4GB
            'cpu' => 100,
            'disk' => 20480,
        ]);

        // Node 2 should be selected (Node 1 doesn't have enough memory)
        $this->assertNotNull($result);
        $this->assertEquals(2, $result['node_id']);
    }

    /**
     * Test that nodes with insufficient CPU are skipped.
     */
    public function test_skips_nodes_with_insufficient_cpu(): void
    {
        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'nodes' => [
                    $this->createNodeData(1, 'Low CPU Node', [
                        'memory' => 16384,
                        'cpu' => 400,
                        'disk' => 102400,
                    ], [
                        'memory' => 12288,
                        'cpu' => 100,       // Only 1 core free
                        'disk' => 80000,
                    ]),
                    $this->createNodeData(2, 'Good Node', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 8192,
                        'cpu' => 400,       // 4 cores free
                        'disk' => 51200,
                    ]),
                ],
                'max_available' => [],
            ]);

        $result = $this->service->selectBestNode(1, [
            'memory' => 4096,
            'cpu' => 200,      // Requires 2 cores
            'disk' => 20480,
        ]);

        $this->assertNotNull($result);
        $this->assertEquals(2, $result['node_id']);
    }

    /**
     * Test that nodes with insufficient disk are skipped.
     */
    public function test_skips_nodes_with_insufficient_disk(): void
    {
        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'nodes' => [
                    $this->createNodeData(1, 'Low Disk Node', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 51200,
                    ], [
                        'memory' => 12288,
                        'cpu' => 600,
                        'disk' => 10240,    // Only 10GB free
                    ]),
                    $this->createNodeData(2, 'Good Node', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 8192,
                        'cpu' => 400,
                        'disk' => 51200,    // 50GB free
                    ]),
                ],
                'max_available' => [],
            ]);

        $result = $this->service->selectBestNode(1, [
            'memory' => 4096,
            'cpu' => 100,
            'disk' => 20480,   // Requires 20GB
        ]);

        $this->assertNotNull($result);
        $this->assertEquals(2, $result['node_id']);
    }

    /**
     * Test that null is returned when no nodes are available.
     */
    public function test_returns_null_when_no_nodes_available(): void
    {
        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'nodes' => [
                    $this->createNodeData(1, 'Full Node', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 1024,   // Too little
                        'cpu' => 50,        // Too little
                        'disk' => 5120,     // Too little
                    ]),
                ],
                'max_available' => [],
            ]);

        $result = $this->service->selectBestNode(1, [
            'memory' => 8192,  // Requires 8GB
            'cpu' => 400,      // Requires 4 cores
            'disk' => 51200,   // Requires 50GB
        ]);

        $this->assertNull($result);
    }

    /**
     * Test that weighted scoring prefers memory headroom (50% weight).
     */
    public function test_weighted_scoring_prefers_memory_headroom(): void
    {
        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'nodes' => [
                    // Node 1: Better disk/cpu but less memory headroom
                    $this->createNodeData(1, 'Better Disk', [
                        'memory' => 16384,
                        'cpu' => 800,
                        'disk' => 204800,
                    ], [
                        'memory' => 8192,    // 50% memory
                        'cpu' => 700,        // 87% cpu
                        'disk' => 184320,    // 90% disk
                    ]),
                    // Node 2: Better memory headroom
                    $this->createNodeData(2, 'Better Memory', [
                        'memory' => 32768,
                        'cpu' => 800,
                        'disk' => 102400,
                    ], [
                        'memory' => 28672,   // 87% memory headroom
                        'cpu' => 400,        // 50% cpu
                        'disk' => 51200,     // 50% disk
                    ]),
                ],
                'max_available' => [],
            ]);

        $result = $this->service->selectBestNode(1, [
            'memory' => 4096,
            'cpu' => 100,
            'disk' => 20480,
        ]);

        // Node 2 should be selected (better memory headroom, 50% weight)
        $this->assertNotNull($result);
        $this->assertEquals(2, $result['node_id']);
    }

    /**
     * Test getMaxAvailable returns location max.
     */
    public function test_get_max_available_returns_location_max(): void
    {
        $expected = [
            'memory' => 16384,
            'cpu' => 800,
            'disk' => 102400,
        ];

        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'location_id' => 1,
                'nodes' => [],
                'max_available' => $expected,
            ]);

        $result = $this->service->getMaxAvailable(1);

        $this->assertEquals($expected, $result);
    }

    public function test_get_max_available_reuses_preloaded_location_snapshot(): void
    {
        $expected = [
            'memory' => 16384,
            'cpu' => 800,
            'disk' => 102400,
        ];
        $this->mockResourceService->shouldNotReceive('getLocationAvailability');

        $result = $this->service->getMaxAvailable(1, [
            'location_id' => 1,
            'nodes' => [],
            'max_available' => $expected,
        ]);

        $this->assertSame($expected, $result);
    }

    public function test_get_max_available_rejects_incomplete_preloaded_snapshot(): void
    {
        $this->mockResourceService->shouldNotReceive('getLocationAvailability');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid location availability snapshot');

        $this->service->getMaxAvailable(1, [
            'location_id' => 1,
            'max_available' => ['memory' => 1024, 'cpu' => -1],
        ]);
    }

    /**
     * Test empty nodes array returns null.
     */
    public function test_empty_nodes_returns_null(): void
    {
        $this->mockResourceService->shouldReceive('getLocationAvailability')
            ->with(1)
            ->andReturn([
                'nodes' => [],
                'max_available' => [],
            ]);

        $result = $this->service->selectBestNode(1, $this->standardResources());

        $this->assertNull($result);
    }
}
