<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services\Validation;

class PricingConfigValidator
{
    /** @throws InvalidPricingConfigException */
    public function validate(array $config): void
    {
        $model = $config['model'] ?? null;

        match ($model) {
            'linear' => $this->validateLinear($config),
            'tiered' => $this->validateTiered($config),
            'base_addon' => $this->validateBaseAddon($config),
            default => throw new InvalidPricingConfigException(
                'Unknown pricing model: ' . var_export($model, true)
            ),
        };
    }

    private function validateLinear(array $config): void
    {
        $this->validateOptionalBasePrice($config);

        if (! array_key_exists('rate_per_unit', $config)) {
            throw new InvalidPricingConfigException('Missing required key: rate_per_unit');
        }

        if (! is_numeric($config['rate_per_unit']) || $config['rate_per_unit'] < 0) {
            throw new InvalidPricingConfigException('rate_per_unit must be a non-negative number');
        }
    }

    private function validateTiered(array $config): void
    {
        $this->validateOptionalBasePrice($config);

        if (! array_key_exists('tiers', $config)) {
            throw new InvalidPricingConfigException('Missing required key: tiers');
        }

        if (! is_array($config['tiers']) || empty($config['tiers'])) {
            throw new InvalidPricingConfigException('tiers must be a non-empty array of tiers');
        }

        $previousCap = 0;
        $lastIndex = array_key_last($config['tiers']);

        foreach ($config['tiers'] as $index => $tier) {
            if (! is_array($tier)) {
                throw new InvalidPricingConfigException("tiers[{$index}] must be an array");
            }

            if (! array_key_exists('up_to', $tier) || ! array_key_exists('rate', $tier)) {
                throw new InvalidPricingConfigException("tiers[{$index}] missing up_to or rate");
            }

            if (! is_numeric($tier['rate']) || $tier['rate'] < 0) {
                throw new InvalidPricingConfigException("tiers[{$index}].rate must be non-negative");
            }

            if ($tier['up_to'] === null || $tier['up_to'] === '') {
                if ($index !== $lastIndex) {
                    throw new InvalidPricingConfigException("tiers[{$index}].up_to may only be unlimited on the final tier");
                }

                continue;
            }

            if (! is_numeric($tier['up_to']) || $tier['up_to'] <= $previousCap) {
                throw new InvalidPricingConfigException(
                    "tiers[{$index}].up_to must be strictly greater than previous tier ({$previousCap})"
                );
            }

            $previousCap = $tier['up_to'];
        }
    }

    private function validateBaseAddon(array $config): void
    {
        $this->validateOptionalBasePrice($config);

        if (! array_key_exists('included_units', $config)) {
            throw new InvalidPricingConfigException('Missing required key: included_units');
        }

        if (! is_numeric($config['included_units']) || $config['included_units'] < 0) {
            throw new InvalidPricingConfigException('included_units must be a non-negative number');
        }

        if (! array_key_exists('overage_rate', $config)) {
            throw new InvalidPricingConfigException('Missing required key: overage_rate');
        }

        if (! is_numeric($config['overage_rate']) || $config['overage_rate'] < 0) {
            throw new InvalidPricingConfigException('overage_rate must be a non-negative number');
        }
    }

    private function validateOptionalBasePrice(array $config): void
    {
        if (! array_key_exists('base_price', $config)) {
            return;
        }

        if (! is_numeric($config['base_price']) || $config['base_price'] < 0) {
            throw new InvalidPricingConfigException('base_price must be a non-negative number');
        }
    }
}

class InvalidPricingConfigException extends \RuntimeException {}
