<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AvailabilityApiTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        require __DIR__.'/../../routes/api.php';
    }

    public function test_legacy_independent_maximum_availability_route_is_removed(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->loginUser($user))
            ->getJson('/api/dynamic-pterodactyl/availability/1')
            ->assertNotFound();
    }

    public function test_legacy_extension_pricing_routes_are_removed(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->loginUser($user))
            ->postJson('/api/dynamic-pterodactyl/pricing/calculate', [])
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession($this->loginUser($user))
            ->getJson('/api/dynamic-pterodactyl/pricing/config/1')
            ->assertNotFound();
    }
}
