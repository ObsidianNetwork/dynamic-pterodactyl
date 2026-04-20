<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Events\CartItem\Created as CartItemCreated;
use App\Events\CartItem\Deleted as CartItemDeleted;
use App\Events\Invoice\Paid as InvoicePaid;
use App\Events\Service\Created as ServiceCreated;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\CartItemCreatedListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\CartItemDeletedListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\InvoicePaidListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\ServiceCreatedListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

#[ExtensionMeta(
    name: 'Dynamic Pterodactyl',
    description: 'Dynamic resource sliders (RAM/CPU/Disk), real-time availability, and 15-min reservations for Pterodactyl products.',
    version: '3.1.0',
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
        // Register routes
        require __DIR__ . '/routes/api.php';

        // Register views (for admin pages if any)
        View::addNamespace('dynamic-pterodactyl', __DIR__ . '/resources/views');

        // Register event listeners for cart and checkout flow (reservations)
        $this->registerEventListeners();

        // Note: Frontend sliders now handled by native Paymenter dynamic_slider config option type
        // The extension now only manages resource reservations and availability checks

        // Scheduled cleanup: transition expired pending reservations.
        // Keeps admin dashboards accurate and preserves the TTL guarantee on confirm().
        Schedule::call(fn () => app(ReservationService::class)->cleanupExpired())
            ->everyMinute()
            ->name('dynamic-pterodactyl:cleanup-expired-reservations')
            ->withoutOverlapping();
    }

    /**
     * Register Paymenter event listeners for the checkout flow
     */
    private function registerEventListeners(): void
    {
        // Cart item created - create resource reservation
        Event::listen(CartItemCreated::class, CartItemCreatedListener::class);

        // Cart item deleted - cancel reservation
        Event::listen(CartItemDeleted::class, CartItemDeletedListener::class);

        // Invoice paid - confirm reservation
        Event::listen(InvoicePaid::class, InvoicePaidListener::class);

        // Service created - log linkage (tracking only)
        Event::listen(ServiceCreated::class, ServiceCreatedListener::class);
    }
}
