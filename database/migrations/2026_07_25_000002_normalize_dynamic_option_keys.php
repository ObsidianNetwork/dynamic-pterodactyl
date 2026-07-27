<?php

use App\Models\Service;
use App\Support\StrictInteger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize only after every affected option and service property has
     * passed an exact, collation-independent preflight.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $optionIds = DB::table('config_options')
                ->join(
                    'config_option_products',
                    'config_option_products.config_option_id',
                    '=',
                    'config_options.id'
                )
                ->join(
                    'products',
                    'products.id',
                    '=',
                    'config_option_products.product_id'
                )
                ->join(
                    'extensions',
                    'extensions.id',
                    '=',
                    'products.server_id'
                )
                ->where('extensions.type', 'server')
                ->where('extensions.extension', 'Pterodactyl')
                ->whereNull('config_options.parent_id')
                ->whereIn(
                    'config_options.type',
                    ['dynamic_slider', 'select']
                )
                ->distinct()
                ->pluck('config_options.id');
            $options = DB::table('config_options')
                ->whereIn('id', $optionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'name',
                    'type',
                    'env_variable',
                    'metadata',
                ]);
            $optionUpdates = [];
            $serviceIdsByResource = [
                'memory' => [],
                'cpu' => [],
                'disk' => [],
            ];

            foreach ($options as $option) {
                try {
                    $metadata = is_string($option->metadata)
                        ? json_decode(
                            $option->metadata,
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        )
                        : (array) $option->metadata;
                } catch (JsonException $exception) {
                    throw new RuntimeException(
                        "Dynamic option {$option->id} has invalid metadata.",
                        previous: $exception
                    );
                }
                if (
                    $option->type === 'dynamic_slider'
                    && (
                        ! is_array($metadata)
                        || array_is_list($metadata)
                    )
                ) {
                    throw new RuntimeException(
                        "Dynamic option {$option->id} has invalid metadata."
                    );
                }
                $resourceType = strtolower(
                    (string) ($metadata['resource_type'] ?? '')
                );
                $isResource = $option->type === 'dynamic_slider'
                    && array_key_exists(
                        $resourceType,
                        $serviceIdsByResource
                    );
                $isLocation = strtolower((string) $option->name)
                    === 'location';

                if (! $isResource && ! $isLocation) {
                    continue;
                }

                $normalizedKey = $isResource
                    ? $resourceType
                    : 'location';
                $optionUpdates[(int) $option->id] = $normalizedKey;

                if (! $isResource) {
                    continue;
                }

                $productIds = DB::table('config_option_products')
                    ->join(
                        'products',
                        'products.id',
                        '=',
                        'config_option_products.product_id'
                    )
                    ->join(
                        'extensions',
                        'extensions.id',
                        '=',
                        'products.server_id'
                    )
                    ->where(
                        'config_option_products.config_option_id',
                        $option->id
                    )
                    ->where('extensions.type', 'server')
                    ->where('extensions.extension', 'Pterodactyl')
                    ->pluck('config_option_products.product_id');
                $serviceIds = DB::table('services')
                    ->whereIn('product_id', $productIds)
                    ->pluck('id');

                foreach ($serviceIds as $serviceId) {
                    $serviceIdsByResource[$normalizedKey][
                        (int) $serviceId
                    ] = true;
                }
            }

            $propertyMutations = $this->preflightPropertyMutations(
                $serviceIdsByResource
            );

            foreach ($optionUpdates as $optionId => $normalizedKey) {
                DB::table('config_options')
                    ->where('id', $optionId)
                    ->update(['env_variable' => $normalizedKey]);
            }

            foreach ($propertyMutations as $mutation) {
                if ($mutation['action'] === 'delete') {
                    DB::table('properties')
                        ->where('id', $mutation['id'])
                        ->delete();

                    continue;
                }

                DB::table('properties')
                    ->where('id', $mutation['id'])
                    ->update(['key' => $mutation['key']]);
            }
        });
    }

    /**
     * @param  array<string, array<int, bool>>  $serviceIdsByResource
     * @return array<int, array{
     *     action: 'delete'|'rename',
     *     id: int,
     *     key?: string
     * }>
     */
    private function preflightPropertyMutations(
        array $serviceIdsByResource
    ): array {
        $allServiceIds = [];
        foreach ($serviceIdsByResource as $serviceIds) {
            foreach (array_keys($serviceIds) as $serviceId) {
                $allServiceIds[$serviceId] = true;
            }
        }

        if ($allServiceIds === []) {
            return [];
        }

        $propertiesByService = [];
        $properties = DB::table('properties')
            ->where('model_type', Service::class)
            ->whereIn('model_id', array_keys($allServiceIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'model_id', 'key', 'value']);

        foreach ($properties as $property) {
            $propertiesByService[(int) $property->model_id][] =
                $property;
        }

        $mutations = [];
        foreach ($serviceIdsByResource as $normalizedKey => $serviceIds) {
            foreach (array_keys($serviceIds) as $serviceId) {
                $matching = array_values(array_filter(
                    $propertiesByService[$serviceId] ?? [],
                    static fn (object $property): bool => strtolower(trim((string) $property->key))
                            === $normalizedKey
                ));

                $lower = null;
                $upper = null;
                foreach ($matching as $property) {
                    $storedKey = (string) $property->key;
                    if ($storedKey === $normalizedKey) {
                        if ($lower !== null) {
                            throw new RuntimeException(
                                "Service {$serviceId} has duplicate "
                                ."[{$normalizedKey}] properties."
                            );
                        }
                        $lower = $property;
                    } elseif (
                        $storedKey === strtoupper($normalizedKey)
                    ) {
                        if ($upper !== null) {
                            throw new RuntimeException(
                                "Service {$serviceId} has duplicate "
                                ."[{$normalizedKey}] properties."
                            );
                        }
                        $upper = $property;
                    } else {
                        throw new RuntimeException(
                            "Service {$serviceId} has unsupported "
                            ."resource key casing [{$storedKey}]."
                        );
                    }
                }

                $lowerValue = $this->strictPropertyValue(
                    $lower,
                    $serviceId,
                    $normalizedKey
                );
                $upperValue = $this->strictPropertyValue(
                    $upper,
                    $serviceId,
                    $normalizedKey
                );

                if ($lower !== null && $upper !== null) {
                    if ($lowerValue !== $upperValue) {
                        throw new RuntimeException(
                            "Service {$serviceId} has conflicting "
                            ."[{$normalizedKey}] property values."
                        );
                    }

                    $mutations[(int) $upper->id] = [
                        'action' => 'delete',
                        'id' => (int) $upper->id,
                    ];
                } elseif ($upper !== null) {
                    $mutations[(int) $upper->id] = [
                        'action' => 'rename',
                        'id' => (int) $upper->id,
                        'key' => $normalizedKey,
                    ];
                }
            }
        }

        ksort($mutations);

        return array_values($mutations);
    }

    private function strictPropertyValue(
        ?object $property,
        int $serviceId,
        string $normalizedKey
    ): ?int {
        if ($property === null) {
            return null;
        }

        $value = StrictInteger::parseStoredDecimal($property->value);
        if ($value === null) {
            throw new RuntimeException(
                "Service {$serviceId} has a non-integer "
                ."[{$normalizedKey}] property value."
            );
        }

        return $value;
    }

    public function down(): void
    {
        // Key normalization is intentionally irreversible. Reintroducing
        // uppercase keys would make Pterodactyl ignore slider values again.
    }
};
