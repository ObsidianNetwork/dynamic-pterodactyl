<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Paymenter\Extensions\Others\DynamicPterodactyl\Services\Validation\InvalidPricingConfigException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\Validation\PricingConfigValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PricingConfigValidatorTest extends TestCase
{
    #[DataProvider('validConfigProvider')]
    public function test_validate_accepts_valid_configs(array $config): void
    {
        $validator = new PricingConfigValidator();

        $validator->validate($config);

        $this->assertTrue(true);
    }

    #[DataProvider('invalidConfigProvider')]
    public function test_validate_rejects_invalid_configs(array $config, string $message): void
    {
        $validator = new PricingConfigValidator();

        $this->expectException(InvalidPricingConfigException::class);
        $this->expectExceptionMessage($message);

        $validator->validate($config);
    }

    public static function validConfigProvider(): array
    {
        return [
            'valid linear' => [[
                'model' => 'linear',
                'base_price' => 5.00,
                'rate_per_unit' => 0.50,
            ]],
            'valid tiered' => [[
                'model' => 'tiered',
                'base_price' => 0,
                'tiers' => [
                    ['up_to' => 4, 'rate' => 1.00],
                    ['up_to' => 8, 'rate' => 0.80],
                    ['up_to' => null, 'rate' => 0.60],
                ],
            ]],
            'valid base addon' => [[
                'model' => 'base_addon',
                'base_price' => 10.00,
                'included_units' => 4,
                'overage_rate' => 1.25,
            ]],
            'valid base plus addon alias' => [[
                'model' => 'base_plus_addon',
                'included_units' => 2,
                'overage_rate' => 3.50,
            ]],
        ];
    }

    public static function invalidConfigProvider(): array
    {
        return [
            'unknown model null' => [
                ['model' => null],
                'Unknown pricing model: NULL',
            ],
            'unknown model string' => [
                ['model' => 'mystery'],
                "Unknown pricing model: 'mystery'",
            ],
            'missing linear rate' => [
                ['model' => 'linear'],
                'Missing required key: rate_per_unit',
            ],
            'negative linear rate' => [
                ['model' => 'linear', 'rate_per_unit' => -1],
                'rate_per_unit must be a non-negative number',
            ],
            'non numeric linear rate' => [
                ['model' => 'linear', 'rate_per_unit' => 'abc'],
                'rate_per_unit must be a non-negative number',
            ],
            'negative base price linear' => [
                ['model' => 'linear', 'base_price' => -5, 'rate_per_unit' => 1],
                'base_price must be a non-negative number',
            ],
            'missing tiers' => [
                ['model' => 'tiered'],
                'Missing required key: tiers',
            ],
            'empty tiers' => [
                ['model' => 'tiered', 'tiers' => []],
                'tiers must be a non-empty array of tiers',
            ],
            'tier item not array' => [
                ['model' => 'tiered', 'tiers' => ['oops']],
                'tiers[0] must be an array',
            ],
            'tier missing up_to' => [
                ['model' => 'tiered', 'tiers' => [['rate' => 1.00]]],
                'tiers[0] missing up_to or rate',
            ],
            'tier missing rate' => [
                ['model' => 'tiered', 'tiers' => [['up_to' => 4]]],
                'tiers[0] missing up_to or rate',
            ],
            'tier decreasing cap' => [
                ['model' => 'tiered', 'tiers' => [['up_to' => 4, 'rate' => 1.00], ['up_to' => 2, 'rate' => 0.80]]],
                'tiers[1].up_to must be strictly greater than previous tier (4)',
            ],
            'tier non numeric cap' => [
                ['model' => 'tiered', 'tiers' => [['up_to' => 'abc', 'rate' => 1.00]]],
                'tiers[0].up_to must be strictly greater than previous tier (0)',
            ],
            'tier negative rate' => [
                ['model' => 'tiered', 'tiers' => [['up_to' => 4, 'rate' => -1.00]]],
                'tiers[0].rate must be non-negative',
            ],
            'tier unlimited not last' => [
                ['model' => 'tiered', 'tiers' => [['up_to' => null, 'rate' => 1.00], ['up_to' => 8, 'rate' => 0.80]]],
                'tiers[0].up_to may only be unlimited on the final tier',
            ],
            'missing included units' => [
                ['model' => 'base_addon', 'overage_rate' => 1.5],
                'Missing required key: included_units',
            ],
            'missing overage rate' => [
                ['model' => 'base_addon', 'included_units' => 4],
                'Missing required key: overage_rate',
            ],
            'negative included units' => [
                ['model' => 'base_addon', 'included_units' => -1, 'overage_rate' => 1.5],
                'included_units must be a non-negative number',
            ],
            'non numeric included units' => [
                ['model' => 'base_addon', 'included_units' => 'bad', 'overage_rate' => 1.5],
                'included_units must be a non-negative number',
            ],
            'negative overage rate' => [
                ['model' => 'base_addon', 'included_units' => 4, 'overage_rate' => -1],
                'overage_rate must be a non-negative number',
            ],
            'non numeric overage rate' => [
                ['model' => 'base_addon', 'included_units' => 4, 'overage_rate' => 'bad'],
                'overage_rate must be a non-negative number',
            ],
        ];
    }
}
