<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class NormalizeDynamicOptionKeysMigrationTest extends LaravelTestCase
{
    use DatabaseTransactions;

    public function test_uppercase_only_property_is_renamed_without_collation_self_match(): void
    {
        [$service, $options] = $this->dynamicService(['memory']);
        $propertyId = $this->insertProperty(
            $service,
            'MEMORY',
            '4096.0000'
        );

        $this->runMigration();

        $this->assertSame(
            'memory',
            DB::table('config_options')
                ->where('id', $options['memory']->id)
                ->value('env_variable')
        );
        $this->assertDatabaseHas('properties', [
            'id' => $propertyId,
            'model_type' => Service::class,
            'model_id' => $service->id,
            'key' => 'memory',
            'value' => '4096.0000',
        ]);
        $this->assertSame(
            1,
            DB::table('properties')
                ->where('model_type', Service::class)
                ->where('model_id', $service->id)
                ->count()
        );
    }

    public function test_equal_uppercase_and_lowercase_properties_keep_the_canonical_row(): void
    {
        $this->requireCaseSensitivePropertyKeys();
        [$service] = $this->dynamicService(['memory']);
        $upperId = $this->insertProperty(
            $service,
            'MEMORY',
            '4096.0000'
        );
        $lowerId = $this->insertProperty(
            $service,
            'memory',
            '4096'
        );

        $this->runMigration();

        $this->assertDatabaseMissing('properties', ['id' => $upperId]);
        $this->assertDatabaseHas('properties', [
            'id' => $lowerId,
            'key' => 'memory',
            'value' => '4096',
        ]);
    }

    public function test_conflicting_duplicate_values_abort_before_mutation(): void
    {
        $this->requireCaseSensitivePropertyKeys();
        [$service, $options] = $this->dynamicService(['memory']);
        $this->insertProperty($service, 'MEMORY', '4096');
        $this->insertProperty($service, 'memory', '8192');

        try {
            $this->runMigration();
            $this->fail(
                'Conflicting legacy resource properties must block migration.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'conflicting [memory] property values',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            'MEMORY',
            DB::table('config_options')
                ->where('id', $options['memory']->id)
                ->value('env_variable')
        );
        $this->assertSame(
            2,
            DB::table('properties')
                ->where('model_type', Service::class)
                ->where('model_id', $service->id)
                ->count()
        );
    }

    public function test_non_integer_resource_value_aborts_before_mutation(): void
    {
        [$service, $options] = $this->dynamicService(['memory']);
        $propertyId = $this->insertProperty(
            $service,
            'MEMORY',
            '4096.5'
        );

        try {
            $this->runMigration();
            $this->fail(
                'Fractional resource properties must block migration.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'non-integer [memory] property value',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            'MEMORY',
            DB::table('config_options')
                ->where('id', $options['memory']->id)
                ->value('env_variable')
        );
        $this->assertDatabaseHas('properties', [
            'id' => $propertyId,
            'key' => 'MEMORY',
            'value' => '4096.5',
        ]);
    }

    public function test_later_invalid_key_casing_blocks_every_planned_change(): void
    {
        [$service, $options] = $this->dynamicService([
            'memory',
            'cpu',
        ]);
        $memoryId = $this->insertProperty(
            $service,
            'MEMORY',
            '4096'
        );
        $cpuId = $this->insertProperty($service, 'Cpu', '200');

        try {
            $this->runMigration();
            $this->fail(
                'Unexpected resource-key casing must block migration.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'unsupported resource key casing [Cpu]',
                $exception->getMessage()
            );
        }

        foreach ($options as $resource => $option) {
            $this->assertSame(
                strtoupper($resource),
                DB::table('config_options')
                    ->where('id', $option->id)
                    ->value('env_variable')
            );
        }
        $this->assertDatabaseHas('properties', [
            'id' => $memoryId,
            'key' => 'MEMORY',
        ]);
        $this->assertDatabaseHas('properties', [
            'id' => $cpuId,
            'key' => 'Cpu',
        ]);
    }

    public function test_later_malformed_metadata_blocks_every_planned_change(): void
    {
        [$service, $options] = $this->dynamicService([
            'memory',
            'cpu',
        ]);
        $memoryId = $this->insertProperty(
            $service,
            'MEMORY',
            '4096'
        );
        DB::table('config_options')
            ->where('id', $options['cpu']->id)
            ->update(['metadata' => '[]']);

        try {
            $this->runMigration();
            $this->fail(
                'Malformed slider metadata must block migration.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "Dynamic option {$options['cpu']->id} has invalid metadata",
                $exception->getMessage()
            );
        }

        foreach ($options as $resource => $option) {
            $this->assertSame(
                strtoupper($resource),
                DB::table('config_options')
                    ->where('id', $option->id)
                    ->value('env_variable')
            );
        }
        $this->assertDatabaseHas('properties', [
            'id' => $memoryId,
            'key' => 'MEMORY',
            'value' => '4096',
        ]);
    }

    /**
     * @param  array<int, string>  $resources
     * @return array{
     *     Service,
     *     array<string, ConfigOption>
     * }
     */
    private function dynamicService(array $resources): array
    {
        $server = Server::query()->create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $product = Product::factory()->create([
            'server_id' => $server->id,
        ]);
        $user = User::factory()->create();
        $serviceId = DB::table('services')->insertGetId([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => Service::STATUS_CANCELLED,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = Service::query()->findOrFail($serviceId);
        $options = [];

        foreach ($resources as $resource) {
            $option = ConfigOption::query()->create([
                'name' => ucfirst($resource),
                'env_variable' => strtoupper($resource),
                'type' => 'dynamic_slider',
                'sort' => 0,
                'hidden' => false,
                'upgradable' => true,
                'metadata' => [
                    'resource_type' => $resource,
                    'min' => 1,
                    'max' => 100000,
                    'step' => 1,
                    'default' => 1,
                    'display_divisor' => 1,
                    'pricing' => [
                        'model' => 'linear',
                        'rate_per_unit' => 0,
                    ],
                ],
            ]);
            DB::table('config_option_products')->insert([
                'config_option_id' => $option->id,
                'product_id' => $product->id,
            ]);
            $options[$resource] = $option;
        }

        return [$service, $options];
    }

    private function insertProperty(
        Service $service,
        string $key,
        string $value
    ): int {
        return DB::table('properties')->insertGetId([
            'custom_property_id' => null,
            'name' => ucfirst(strtolower($key)),
            'key' => $key,
            'value' => $value,
            'model_type' => Service::class,
            'model_id' => $service->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runMigration(): void
    {
        $migration = require __DIR__
            .'/../../database/migrations/'
            .'2026_07_25_000002_normalize_dynamic_option_keys.php';

        $migration->up();
    }

    private function requireCaseSensitivePropertyKeys(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped(
                'The production MariaDB unique key intentionally rejects '
                .'case-only duplicate properties.'
            );
        }
    }
}
