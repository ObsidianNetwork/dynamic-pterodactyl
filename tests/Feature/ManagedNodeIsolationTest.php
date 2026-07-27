<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Exceptions\PermanentProvisioningException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;
use Paymenter\Extensions\Servers\Pterodactyl\Pterodactyl;

class ManagedNodeIsolationTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private Pterodactyl $provisioner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisioner = new Pterodactyl([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]);
        DB::table('ptero_node_capacity_policies')->insert([
            'panel_identity' => hash('sha256', 'https://panel.example.com'),
            'node_uuid' => '00000000-0000-0000-0000-000000000007',
            'node_id' => 7,
            'location_id' => 3,
            'cpu_capacity_percent' => 800,
            'cpu_overcommit_bps' => 10000,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_static_create_rejects_a_managed_node(): void
    {
        $this->assertStaticCreateRejected(
            ['node' => 7, 'location_ids' => [3]],
            'dedicated to reservation-backed dynamic products'
        );
    }

    public function test_static_auto_deploy_rejects_any_scope_that_can_reach_a_managed_node(): void
    {
        $this->assertStaticCreateRejected(
            ['node' => null, 'location_ids' => [3, 4]],
            'location with reservation-managed nodes'
        );
        $this->assertStaticCreateRejected(
            ['node' => null, 'location_ids' => []],
            'spans nodes dedicated to reservation-backed dynamic products'
        );
    }

    public function test_static_create_preserves_unmanaged_node_and_location_paths(): void
    {
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'assertStaticCreateAvoidsManagedNodes'
        );
        $method->setAccessible(true);

        $method->invoke(
            $this->provisioner,
            ['node' => 8, 'location_ids' => [3]]
        );
        $method->invoke(
            $this->provisioner,
            ['node' => null, 'location_ids' => [4]]
        );

        $this->addToAssertionCount(2);
    }

    public function test_non_capacity_upgrade_rejects_managed_node_and_preserves_unmanaged_node(): void
    {
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'assertStaticUpgradeAvoidsManagedNode'
        );
        $method->setAccessible(true);

        try {
            $method->invoke($this->provisioner, [
                'attributes' => ['node' => 7],
            ]);
            $this->fail('Expected managed-node upgrade isolation to fail closed.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $actual = $exception instanceof \ReflectionException
                ? $exception
                : ($exception->getPrevious() ?? $exception);
            $this->assertInstanceOf(
                PermanentProvisioningException::class,
                $actual
            );
            $this->assertStringContainsString(
                'capacity-aware upgrade',
                $actual->getMessage()
            );
        }

        $method->invoke($this->provisioner, [
            'attributes' => ['node' => 8],
        ]);
        $this->addToAssertionCount(1);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function assertStaticCreateRejected(
        array $settings,
        string $message
    ): void {
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'assertStaticCreateAvoidsManagedNodes'
        );
        $method->setAccessible(true);

        try {
            $method->invoke($this->provisioner, $settings);
            $this->fail('Expected static managed-node isolation to fail closed.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $actual = $exception->getPrevious() ?? $exception;
            $this->assertInstanceOf(
                PermanentProvisioningException::class,
                $actual
            );
            $this->assertStringContainsString(
                $message,
                $actual->getMessage()
            );
        }
    }
}
