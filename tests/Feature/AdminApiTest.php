<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AdminApiTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../../routes/api.php';
    }

    private function makeAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeRegularUser(): User
    {
        return User::factory()->create(['role_id' => null]);
    }

    private function makeReservation(array $attrs = []): ResourceReservation
    {
        return ResourceReservation::create(array_merge([
            'token' => Str::random(64),
            'user_id' => null,
            'node_id' => 1,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 9.99,
            'pricing_breakdown' => [],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ], $attrs));
    }

    public function test_unauthenticated_reservations_list_redirects_to_login(): void
    {
        $response = $this->get('/api/dynamic-pterodactyl/admin/reservations');
        $response->assertStatus(302);
    }

    public function test_non_admin_user_gets_403(): void
    {
        $user = $this->makeRegularUser();
        $response = $this->actingAs($user)
            ->withSession($this->loginUser($user))
            ->getJson('/api/dynamic-pterodactyl/admin/reservations');

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }

    public function test_admin_lists_reservations_paginated(): void
    {
        $admin = $this->makeAdminUser();
        $this->makeReservation();
        $this->makeReservation();
        $this->makeReservation();

        $response = $this->actingAs($admin)
            ->withSession($this->loginUser($admin))
            ->getJson('/api/dynamic-pterodactyl/admin/reservations');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertCount(3, $response->json('data.data'));
        $this->assertEquals(25, $response->json('data.per_page'));
    }

    public function test_admin_filters_by_status(): void
    {
        $admin = $this->makeAdminUser();
        $this->makeReservation(['status' => 'pending']);
        $this->makeReservation(['status' => 'pending']);
        $this->makeReservation(['status' => 'cancelled']);

        $response = $this->actingAs($admin)
            ->withSession($this->loginUser($admin))
            ->getJson('/api/dynamic-pterodactyl/admin/reservations?status=pending');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
        foreach ($response->json('data.data') as $row) {
            $this->assertEquals('pending', $row['status']);
        }
    }

    public function test_admin_cancels_reservation_with_reason(): void
    {
        $admin = $this->makeAdminUser();
        $reservation = $this->makeReservation(['status' => 'pending']);

        $response = $this->actingAs($admin)
            ->withSession($this->loginUser($admin))
            ->postJson(
                '/api/dynamic-pterodactyl/admin/reservations/'.$reservation->token.'/cancel',
                ['reason' => 'Admin cancelled for testing']
            );

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'message' => 'Reservation cancelled']);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_admin_cancel_requires_reason(): void
    {
        $admin = $this->makeAdminUser();
        $reservation = $this->makeReservation(['status' => 'pending']);

        $response = $this->actingAs($admin)
            ->withSession($this->loginUser($admin))
            ->postJson(
                '/api/dynamic-pterodactyl/admin/reservations/'.$reservation->token.'/cancel',
                []
            );

        $response->assertStatus(422);
    }

    public function test_admin_capacity_endpoint_returns_structure(): void
    {
        $admin = $this->makeAdminUser();

        $mock = $this->mock(ResourceCalculationService::class);
        $mock->shouldReceive('buildClusterSnapshot')
            ->once()
            ->andReturn([
                'locations' => [['id' => 1, 'long' => 'Data Center 1', 'short' => 'dc1']],
                'nodes' => [
                    1 => [
                        'node_availability' => ['node_id' => 1, 'name' => 'Node 1'],
                    ],
                ],
                'by_location' => [
                    1 => [
                        'nodes' => [1],
                        'totals' => ['memory' => 65536, 'cpu' => 800, 'disk' => 512000],
                        'allocated' => ['memory' => 32768, 'cpu' => 400, 'disk' => 256000],
                        'available' => ['memory' => 32768, 'cpu' => 400, 'disk' => 256000],
                    ],
                ],
                'generated_at' => \now()->toIso8601String(),
            ]);

        $response = $this->actingAs($admin)
            ->withSession($this->loginUser($admin))
            ->getJson('/api/dynamic-pterodactyl/admin/capacity');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertArrayHasKey('locations', $response->json('data'));
        $this->assertArrayHasKey('generated_at', $response->json('data'));
        $this->assertCount(1, $response->json('data.locations'));
        $this->assertEquals('Data Center 1', $response->json('data.locations.0.name'));
    }

    public function test_customer_cannot_read_per_node_availability(): void
    {
        $user = $this->makeRegularUser();
        $response = $this->actingAs($user)
            ->withSession($this->loginUser($user))
            ->getJson('/api/dynamic-pterodactyl/admin/availability/1/nodes');

        $response->assertStatus(403);
    }

    public function test_admin_can_read_per_node_availability(): void
    {
        $admin = $this->makeAdminUser();

        $mock = $this->mock(ResourceCalculationService::class);
        $mock->shouldReceive('getLocationAvailability')
            ->once()
            ->with(1)
            ->andReturn([
                'location_id' => 1,
                'nodes' => [['node_id' => 1, 'name' => 'Node 1']],
                'total_capacity' => ['memory' => 65536, 'cpu' => 800, 'disk' => 512000],
                'total_allocated' => ['memory' => 32768, 'cpu' => 400, 'disk' => 256000],
                'max_available' => ['memory' => 32768, 'cpu' => 400, 'disk' => 256000],
            ]);

        $response = $this->actingAs($admin)
            ->withSession($this->loginUser($admin))
            ->getJson('/api/dynamic-pterodactyl/admin/availability/1/nodes');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertArrayHasKey('nodes', $response->json('data'));
        $this->assertCount(1, $response->json('data.nodes'));
    }
}
