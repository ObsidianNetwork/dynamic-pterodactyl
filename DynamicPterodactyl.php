<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Events\CartItem\Created as CartItemCreated;
use App\Events\CartItem\Deleting as CartItemDeleting;
use App\Events\CartItem\Updated as CartItemUpdated;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\CartItemCreatedListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\CartItemDeletedListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Policies\ResourceReservationPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AlertService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\LegacyReservationReadinessService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\UpgradeReservationService;

#[ExtensionMeta(
    name: 'Dynamic Pterodactyl',
    description: 'Dynamic RAM/CPU/disk stock with live quotes, short cart holds, and seven-day invoice guarantees for Pterodactyl products.',
    version: '4.0.0',
    author: 'Paymenter',
    url: '',
    icon: 'heroicon-o-server',
)]
/**
 * DynamicPterodactyl Extension
 *
 * Companion extension that adds dynamic resource sliders to Pterodactyl products.
 * Works alongside the built-in Pterodactyl server extension.
 *
 * @see README.md for architecture overview
 */
class DynamicPterodactyl extends Extension
{
    public function __construct(public $config = []) {}

    /**
     * Extension configuration shown in admin panel.
     */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'pterodactyl_url',
                'label' => 'Pterodactyl Panel URL',
                'type' => 'text',
                'description' => 'Full URL to your Pterodactyl panel (e.g., https://panel.example.com)',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'pterodactyl_api_key',
                'label' => 'Pterodactyl API Key',
                'type' => 'password',
                'description' => 'Application API key with read access to Locations, Nodes, and Servers',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'reservation_ttl',
                'label' => 'Reservation TTL (minutes)',
                'type' => 'number',
                'description' => 'How long resource reservations are held during checkout',
                'default' => 15,
                'validation' => 'integer|min:5|max:60',
            ],
            [
                'name' => 'exclusive_provisioning_control',
                'label' => 'Paymenter exclusively controls eligible nodes',
                'type' => 'checkbox',
                'description' => 'Required for strict stock guarantees. Every node with an enabled CPU policy is dedicated to reservation-backed products: Paymenter blocks its own static creates and non-capacity upgrades, and no administrator, automation, or other billing system may create, move, resize, or assign allocations there.',
                'required' => true,
                'validation' => 'accepted',
            ],
        ];
    }

    /**
     * Called when extension is installed.
     */
    public function installed(): void
    {
        ExtensionHelper::runMigrationsOrFail('extensions/Others/DynamicPterodactyl/database/migrations');
        $this->assertMigrationReady();
    }

    /**
     * Apply every newly shipped extension migration before upgraded code is used.
     */
    public function upgraded($oldVersion = null): void
    {
        ExtensionHelper::runMigrationsOrFail('extensions/Others/DynamicPterodactyl/database/migrations');
        $this->assertMigrationReady();
    }

    /**
     * Shared by install/upgrade and Paymenter's explicit extension migration
     * command so migrations can never report success over unsafe legacy rows.
     */
    public function assertMigrationReady(): void
    {
        app(LegacyReservationReadinessService::class)->assertReady();
    }

    /**
     * Durable reservation, payment-attention, allocation, and CPU-policy
     * history is deliberately retained on uninstall. Core lifecycle guards
     * already require all active work to be drained first; preserving the
     * schema keeps completed fulfillment auditable and makes reinstall safe.
     */
    public function uninstalled(): void
    {
        // Intentionally no destructive migration rollback.
    }

    /**
     * Called on every request if extension is enabled.
     */
    public function boot(): void
    {
        Gate::policy(ResourceReservation::class, ResourceReservationPolicy::class);

        // Register routes
        require __DIR__ . '/routes/api.php';

        // Register views (for admin pages if any)
        View::addNamespace('dynamic-pterodactyl', __DIR__ . '/resources/views');

        // Register event listeners for cart and checkout flow (reservations)
        $this->registerEventListeners();

        \Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig::observe(
            \Paymenter\Extensions\Others\DynamicPterodactyl\Models\Observers\AlertConfigObserver::class
        );

        // Note: Frontend sliders now handled by native Paymenter dynamic_slider config option type
        // The extension now only manages resource reservations and availability checks

        // Scheduled cleanup: transition expired pending reservations.
        // Keeps admin dashboards accurate and preserves the TTL guarantee on confirm().
        Schedule::call(function (): void {
            app(UpgradeReservationService::class)->expireUnpaidUpgrades();
            app(ReservationService::class)->cleanupExpired();
        })
            ->everyMinute()
            ->name('dynamic-pterodactyl:cleanup-expired-reservations')
            ->withoutOverlapping(5);

        Schedule::call(
            function (): void {
                app(ReservationService::class)->reconcileStalledPaidCommitments();
                app(UpgradeReservationService::class)->reconcileStalledUpgrades();
            }
        )
            ->everyTenMinutes()
            ->name('dynamic-pterodactyl:reconcile-paid-commitments')
            ->withoutOverlapping(15);

        Schedule::call(fn () => app(AlertService::class)->checkCapacityAlerts())
            ->everyFiveMinutes()
            ->name('dynamic-pterodactyl:check-capacity-alerts')
            ->withoutOverlapping(10);
    }

    /**
     * Register Paymenter event listeners for the checkout flow
     */
    private function registerEventListeners(): void
    {
        // Cart item created - create resource reservation
        Event::listen(CartItemCreated::class, CartItemCreatedListener::class);

        // Cart item edited - replace or refresh its resource reservation
        Event::listen(CartItemUpdated::class, CartItemCreatedListener::class);

        // Cancel before deletion while the cart-item relationship still exists
        Event::listen(CartItemDeleting::class, CartItemDeletedListener::class);
    }
}
