<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class QuoteRateLimiterService
{
    public const NAME = 'dynamic-pterodactyl-quotes';

    public function __construct(
        private readonly QuoteRateLimitConfigurationService $configuration
    ) {
    }

    public function register(): void
    {
        RateLimiter::for(self::NAME, function (Request $request): array {
            // Resolve and validate on each request. A malformed persisted
            // setting must fail the quote closed instead of silently removing
            // either budget until an administrator repairs the configuration.
            $configuration = $this->configuration->configuration();
            $panelIdentity = $configuration['panel_identity'];

            return [
                Limit::perMinute($configuration['per_ip'])->by(
                    'dynamic-pterodactyl:quotes:ip:'
                    .$panelIdentity.':'.$request->ip()
                ),
                Limit::perMinute($configuration['global'])->by(
                    'dynamic-pterodactyl:quotes:panel:'.$panelIdentity
                ),
            ];
        });
    }
}
