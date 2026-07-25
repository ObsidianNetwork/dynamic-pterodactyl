<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $optionIds = DB::table('config_options')
                ->join(
                    'config_option_products',
                    'config_option_products.config_option_id',
                    '=',
                    'config_options.id'
                )
                ->join('products', 'products.id', '=', 'config_option_products.product_id')
                ->join('extensions', 'extensions.id', '=', 'products.server_id')
                ->where('extensions.type', 'server')
                ->where('extensions.extension', 'Pterodactyl')
                ->whereNull('config_options.parent_id')
                ->whereIn('config_options.type', ['dynamic_slider', 'select'])
                ->distinct()
                ->pluck('config_options.id');
            $options = DB::table('config_options')
                ->whereIn('id', $optionIds)
                ->get(['id', 'name', 'type', 'env_variable', 'metadata']);

            foreach ($options as $option) {
                $metadata = is_string($option->metadata)
                    ? json_decode($option->metadata, true) ?? []
                    : (array) $option->metadata;
                $resourceType = strtolower((string) ($metadata['resource_type'] ?? ''));
                $isResource = $option->type === 'dynamic_slider'
                    && in_array($resourceType, ['memory', 'cpu', 'disk'], true);
                $isLocation = strtolower((string) $option->name) === 'location';

                if (! $isResource && ! $isLocation) {
                    continue;
                }

                $normalizedKey = $isResource ? $resourceType : 'location';
                DB::table('config_options')
                    ->where('id', $option->id)
                    ->update(['env_variable' => $normalizedKey]);

                if (! $isResource) {
                    continue;
                }

                $productIds = DB::table('config_option_products')
                    ->join('products', 'products.id', '=', 'config_option_products.product_id')
                    ->join('extensions', 'extensions.id', '=', 'products.server_id')
                    ->where('config_option_products.config_option_id', $option->id)
                    ->where('extensions.type', 'server')
                    ->where('extensions.extension', 'Pterodactyl')
                    ->pluck('config_option_products.product_id');
                $serviceIds = DB::table('services')
                    ->whereIn('product_id', $productIds)
                    ->pluck('id');

                foreach ($serviceIds as $serviceId) {
                    $upper = DB::table('properties')
                        ->where('model_type', Service::class)
                        ->where('model_id', $serviceId)
                        ->where('key', strtoupper($normalizedKey))
                        ->first();

                    if ($upper === null) {
                        continue;
                    }

                    $lowerExists = DB::table('properties')
                        ->where('model_type', Service::class)
                        ->where('model_id', $serviceId)
                        ->where('key', $normalizedKey)
                        ->exists();

                    if ($lowerExists) {
                        DB::table('properties')->where('id', $upper->id)->delete();
                    } else {
                        DB::table('properties')
                            ->where('id', $upper->id)
                            ->update(['key' => $normalizedKey]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Key normalization is intentionally irreversible. Reintroducing uppercase
        // keys would make Pterodactyl ignore the selected slider values again.
    }
};
