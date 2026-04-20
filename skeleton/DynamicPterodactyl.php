<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl;

use App\Classes\Extension;
use Illuminate\Support\Facades\Event;

/**
 * DynamicPterodactyl Extension
 * 
 * Companion extension that adds dynamic resource sliders to Pterodactyl products.
 * Works alongside the built-in Pterodactyl server extension.
 * 
 * @see /docs/README.md for architecture overview
 */
class DynamicPterodactyl extends Extension
{
    /**
     * Extension metadata
     */
    public function getConfig(): array
    {
        return [
            'name' => 'Dynamic Pterodactyl',
            'description' => 'Dynamic resource sliders with real-time pricing for Pterodactyl products',
            'version' => '0.1.0',
            'author' => 'Your Name',
        ];
    }

    /**
     * Extension settings shown in admin
     */
    public function getSettings(): array
    {
        return [
            [
                'name' => 'pterodactyl_url',
                'label' => 'Pterodactyl Panel URL',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'pterodactyl_api_key',
                'label' => 'Pterodactyl API Key',
                'type' => 'password',
                'required' => true,
            ],
            [
                'name' => 'reservation_ttl',
                'label' => 'Reservation TTL (minutes)',
                'type' => 'number',
                'default' => 15,
            ],
        ];
    }

    /**
     * Called when extension is loaded
     */
    public function boot(): void
    {
        // Register routes
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        
        // Register views
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'dynamic-pterodactyl');
        
        // Register migrations
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        
        // Register event listeners
        $this->registerEventListeners();
        
        // Register scheduled jobs
        $this->registerScheduledJobs();
        
        // Inject frontend scripts
        $this->injectFrontendScripts();
    }

    /**
     * Register Paymenter event listeners
     * @see /docs/04-EVENTS.md
     */
    private function registerEventListeners(): void
    {
        // TODO: Implement event listeners
        // Event::listen(\App\Events\CartItem\Created::class, ...)
    }

    /**
     * Register scheduled cleanup and alert jobs
     * @see /docs/02-SERVICES.md (Scheduled Jobs section)
     */
    private function registerScheduledJobs(): void
    {
        // TODO: Register scheduled jobs
        // $schedule->job(new CleanupExpiredReservations)->everyMinute();
    }

    /**
     * Inject slider JavaScript into product pages
     * @see /docs/06-FRONTEND.md
     */
    private function injectFrontendScripts(): void
    {
        // TODO: Add head scripts injection
    }
}