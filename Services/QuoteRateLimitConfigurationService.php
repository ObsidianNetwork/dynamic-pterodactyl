<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\Extension;
use App\Support\PanelEndpointIdentity;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;

class QuoteRateLimitConfigurationService
{
    public const DEFAULT_PER_IP = 10;

    public const DEFAULT_GLOBAL = 60;

    public const MAX_PER_IP = 60;

    public const MAX_GLOBAL = 240;

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(private readonly ?array $config = null) {}

    /**
     * @return array{per_ip: int, global: int, panel_identity: string}
     */
    public function configuration(): array
    {
        $config = $this->config ?? $this->extensionConfig();
        $panelUrl = trim((string) ($config['pterodactyl_url'] ?? ''));

        try {
            $panelIdentity = PanelEndpointIdentity::hash($panelUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidStockConfigurationException(
                'Dynamic quote rate limiting requires a valid Pterodactyl panel URL.',
                previous: $exception
            );
        }

        return [
            'per_ip' => $this->boundedInteger(
                $config['quote_rate_limit_per_ip'] ?? null,
                self::DEFAULT_PER_IP,
                self::MAX_PER_IP,
                'per-IP quote rate limit'
            ),
            'global' => $this->boundedInteger(
                $config['quote_rate_limit_global'] ?? null,
                self::DEFAULT_GLOBAL,
                self::MAX_GLOBAL,
                'global quote rate limit'
            ),
            'panel_identity' => $panelIdentity,
        ];
    }

    private function boundedInteger(
        mixed $value,
        int $default,
        int $maximum,
        string $label
    ): int {
        if ($value === null || $value === '') {
            return $default;
        }
        if (
            ! is_int($value)
            && ! (
                is_string($value)
                && preg_match('/^(0|[1-9]\d*)$/D', $value) === 1
            )
        ) {
            throw new InvalidStockConfigurationException(
                "The {$label} must be a whole number."
            );
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 1 || $validated > $maximum) {
            throw new InvalidStockConfigurationException(
                "The {$label} must be between 1 and {$maximum} requests per minute."
            );
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extensionConfig(): array
    {
        return Extension::query()
            ->where('extension', 'DynamicPterodactyl')
            ->where('enabled', true)
            ->first()
            ?->settings
            ->pluck('value', 'key')
            ->toArray() ?? [];
    }
}
