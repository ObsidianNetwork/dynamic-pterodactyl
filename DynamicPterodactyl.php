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
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

#[ExtensionMeta(
    name: 'Dynamic Pterodactyl',
    description: 'Dynamic resource sliders (RAM/CPU/Disk), real-time availability, and 15-min reservations for Pterodactyl products.',
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
                'description' => 'Application API key from Pterodactyl admin panel',
                'required' => true,
            ],
            [
                'name' => 'reservation_ttl',
                'label' => 'Reservation TTL (minutes)',
                'type' => 'number',
                'description' => 'How long resource reservations are held during checkout',
                'default' => 15,
                'validation' => 'integer|min:5|max:60',
            ],
        ];
    }

    /**
     * Called when extension is installed.
     */
    public function installed(): void
    {
        ExtensionHelper::runMigrations('extensions/Others/DynamicPterodactyl/database/migrations');
    }

    /**
     * Called when extension is uninstalled.
     */
    public function uninstalled(): void
    {
        ExtensionHelper::rollbackMigrations('extensions/Others/DynamicPterodactyl/database/migrations');
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
        Schedule::call(fn () => app(ReservationService::class)->cleanupExpired())
            ->everyMinute()
            ->name('dynamic-pterodactyl:cleanup-expired-reservations')
            ->withoutOverlapping();

        Schedule::call(fn () => app(AlertService::class)->checkCapacityAlerts())
            ->everyFiveMinutes()
            ->name('dynamic-pterodactyl:check-capacity-alerts')
            ->withoutOverlapping();
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
