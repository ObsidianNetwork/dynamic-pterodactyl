<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Support\PanelEndpointIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\QuoteRateLimitConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\QuoteRateLimiterService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class QuoteRateLimiterServiceTest extends LaravelTestCase
{
    public function test_defaults_create_independent_per_ip_and_panel_global_budgets(): void
    {
        $configuration = new QuoteRateLimitConfigurationService([
            'pterodactyl_url' => 'HTTPS://Panel.Example.com:443/PanelA/',
            'pterodactyl_api_key' => 'must-not-be-in-a-rate-limit-key',
        ]);
        (new QuoteRateLimiterService($configuration))->register();

        $limiter = RateLimiter::limiter(QuoteRateLimiterService::NAME);
        $this->assertIsCallable($limiter);
        $limits = $limiter(Request::create(
            '/api/dynamic-pterodactyl/products/1/resource-quote',
            'POST',
            server: ['REMOTE_ADDR' => '192.0.2.44']
        ));
        $identity = PanelEndpointIdentity::hash(
            'https://panel.example.com/PanelA'
        );

        $this->assertCount(2, $limits);
        $this->assertSame(
            QuoteRateLimitConfigurationService::DEFAULT_PER_IP,
            $limits[0]->maxAttempts
        );
        $this->assertSame(
            QuoteRateLimitConfigurationService::DEFAULT_GLOBAL,
            $limits[1]->maxAttempts
        );
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertSame(60, $limits[1]->decaySeconds);
        $this->assertSame(
            "dynamic-pterodactyl:quotes:ip:{$identity}:192.0.2.44",
            $limits[0]->key
        );
        $this->assertSame(
            "dynamic-pterodactyl:quotes:panel:{$identity}",
            $limits[1]->key
        );
        $this->assertStringNotContainsString(
            'must-not-be-in-a-rate-limit-key',
            $limits[0]->key.$limits[1]->key
        );
    }

    public function test_configured_budgets_are_bounded_and_applied(): void
    {
        $configuration = new QuoteRateLimitConfigurationService([
            'pterodactyl_url' => 'https://panel.example.com',
            'quote_rate_limit_per_ip' => '7',
            'quote_rate_limit_global' => 42,
        ]);
        (new QuoteRateLimiterService($configuration))->register();

        $limits = RateLimiter::limiter(QuoteRateLimiterService::NAME)(
            Request::create('/', 'POST')
        );

        $this->assertSame(7, $limits[0]->maxAttempts);
        $this->assertSame(42, $limits[1]->maxAttempts);
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_persisted_configuration_fails_closed(
        array $configuration,
        string $message
    ): void {
        $service = new QuoteRateLimitConfigurationService([
            'pterodactyl_url' => 'https://panel.example.com',
            ...$configuration,
        ]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage($message);

        $service->configuration();
    }

    public static function invalidConfiguration(): array
    {
        return [
            'zero per IP' => [[
                'quote_rate_limit_per_ip' => 0,
            ], 'per-IP quote rate limit must be between'],
            'excessive per IP' => [[
                'quote_rate_limit_per_ip' => 61,
            ], 'per-IP quote rate limit must be between'],
            'fractional global' => [[
                'quote_rate_limit_global' => '1.5',
            ], 'global quote rate limit must be a whole number'],
            'excessive global' => [[
                'quote_rate_limit_global' => 241,
            ], 'global quote rate limit must be between'],
        ];
    }

    public function test_invalid_panel_url_fails_closed_before_a_key_is_built(): void
    {
        $service = new QuoteRateLimitConfigurationService([
            'pterodactyl_url' => 'not a panel URL',
        ]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage('valid Pterodactyl panel URL');

        $service->configuration();
    }

    public function test_named_limiter_is_wired_only_to_customer_quote_routes(): void
    {
        require __DIR__.'/../../routes/api.php';

        $checkout = Route::getRoutes()->match(Request::create(
            '/api/dynamic-pterodactyl/products/1/resource-quote',
            'POST'
        ));
        $upgrade = Route::getRoutes()->match(Request::create(
            '/api/dynamic-pterodactyl/services/1/upgrade-quote',
            'POST'
        ));
        $admin = Route::getRoutes()->match(Request::create(
            '/api/dynamic-pterodactyl/admin/capacity',
            'GET'
        ));
        $middleware = 'throttle:'.QuoteRateLimiterService::NAME;

        $this->assertContains($middleware, $checkout->gatherMiddleware());
        $this->assertContains($middleware, $upgrade->gatherMiddleware());
        $this->assertNotContains($middleware, $admin->gatherMiddleware());
        $this->assertContains('throttle:30,1', $admin->gatherMiddleware());
    }
}
