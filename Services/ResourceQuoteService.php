<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\Product;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\StockUnavailableException;

class ResourceQuoteService
{
    public function __construct(
        private readonly ProductResourceConfigurationService $configuration,
        private readonly ResourceCalculationService $resources,
        private readonly AllocationSelectionService $allocations
    ) {
    }

    /**
     * Return customer-safe, complete-vector slider bounds. No node identity or
     * raw infrastructure detail leaves this service.
     *
     * @param  array<int|string, mixed>  $submittedOptions
     * @return array{
     *     available: true,
     *     adjusted: bool,
     *     selection: array{memory: int, cpu: int, disk: int},
     *     bounds: array<string, array{config_option_id: int, min: int, max: int, configured_max: int, step: int}>
     * }
     */
    public function quote(
        Product $product,
        array $submittedOptions,
        ?string $excludeReservationToken = null
    ): array {
        $configuration = $this->configuration->forQuote($product, $submittedOptions);
        $availability = $this->resources->getLocationAvailability(
            $configuration['location_id'],
            $excludeReservationToken
        );
        $nodes = array_values(array_filter(
            $availability['nodes'],
            fn (array $node): bool => ($node['eligible'] ?? false)
                && $this->allocations->select(
                    $node['available_allocations'] ?? [],
                    $configuration['allocation_count'],
                    $configuration['required_ports'] ?? [],
                    $configuration['allowed_port_ranges'] ?? [],
                    (bool) ($configuration['dedicated_ip'] ?? false)
                ) !== null
        ));

        if (
            $nodes === []
            && collect($availability['nodes'])->contains(
                fn (array $node): bool => array_intersect(
                    $node['ineligible_reasons'] ?? [],
                    [
                        'cpu_policy_missing',
                        'unlimited_existing_resource',
                        'unbounded_memory_overallocation',
                        'unbounded_disk_overallocation',
                    ]
                ) !== []
            )
        ) {
            throw new InvalidStockConfigurationException(
                'Eligible nodes are missing authoritative bounded resource inventory.'
            );
        }

        if ($nodes === []) {
            throw new StockUnavailableException(
                'No server currently has the required resource and port capacity.'
            );
        }

        $requested = $configuration['resources'];
        $selection = $this->vectorFitsAnyNode($requested, $nodes)
            ? $requested
            : $this->bestAdjustedVector($configuration, $nodes);

        if ($selection === null) {
            throw new StockUnavailableException(
                'No server currently has enough stock for this product minimum.'
            );
        }

        return [
            'available' => true,
            'adjusted' => $selection !== $requested,
            'selection' => $selection,
            'bounds' => $this->conditionalBounds(
                $configuration['sliders'],
                $selection,
                $nodes
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  list<array<string, mixed>>  $nodes
     * @return array{memory: int, cpu: int, disk: int}|null
     */
    private function bestAdjustedVector(array $configuration, array $nodes): ?array
    {
        $candidates = [];

        foreach ($nodes as $node) {
            $candidate = $configuration['resources'];
            $valid = true;

            foreach (['memory', 'cpu', 'disk'] as $resource) {
                if (! isset($configuration['sliders'][$resource])) {
                    if ($candidate[$resource] > $node['available'][$resource]) {
                        $valid = false;
                        break;
                    }

                    continue;
                }

                $slider = $configuration['sliders'][$resource];
                $nodeMaximum = min(
                    $slider['max'],
                    (int) $node['available'][$resource]
                );
                $nodeMaximum = $this->snapDown(
                    $nodeMaximum,
                    $slider['min'],
                    $slider['step']
                );

                if ($nodeMaximum < $slider['min']) {
                    $valid = false;
                    break;
                }

                $candidate[$resource] = min(
                    $candidate[$resource],
                    $nodeMaximum
                );
            }

            if (! $valid || ! $this->vectorFitsNode($candidate, $node)) {
                continue;
            }

            $retention = 0.0;
            foreach ($configuration['sliders'] as $resource => $slider) {
                $retention += $candidate[$resource] / max(1, $configuration['resources'][$resource]);
            }

            $candidates[] = [
                'selection' => $candidate,
                'retention' => $retention,
                'node_id' => (int) $node['node_id'],
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            $retention = $right['retention'] <=> $left['retention'];

            return $retention !== 0
                ? $retention
                : $left['node_id'] <=> $right['node_id'];
        });

        return $candidates[0]['selection'];
    }

    /**
     * Each bound is conditional on the other two selected resources. This
     * prevents independently advertised maxima from coming from different
     * Pterodactyl nodes.
     *
     * @param  array<string, array<string, int>>  $sliders
     * @param  array{memory: int, cpu: int, disk: int}  $selection
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, array{config_option_id: int, min: int, max: int, configured_max: int, step: int}>
     */
    private function conditionalBounds(
        array $sliders,
        array $selection,
        array $nodes
    ): array {
        $bounds = [];

        foreach ($sliders as $resource => $slider) {
            $maximum = null;

            foreach ($nodes as $node) {
                $otherResourcesFit = true;
                foreach (['memory', 'cpu', 'disk'] as $otherResource) {
                    if ($otherResource === $resource) {
                        continue;
                    }

                    if ($selection[$otherResource] > $node['available'][$otherResource]) {
                        $otherResourcesFit = false;
                        break;
                    }
                }

                if (! $otherResourcesFit) {
                    continue;
                }

                $nodeMaximum = $this->snapDown(
                    min($slider['max'], (int) $node['available'][$resource]),
                    $slider['min'],
                    $slider['step']
                );
                if ($nodeMaximum >= $slider['min']) {
                    $maximum = max($maximum ?? $slider['min'], $nodeMaximum);
                }
            }

            if ($maximum === null) {
                throw new StockUnavailableException(
                    'No server can satisfy the complete selected resource combination.'
                );
            }

            $bounds[$resource] = [
                'config_option_id' => $slider['config_option_id'],
                'min' => $slider['min'],
                'max' => $maximum,
                'configured_max' => $slider['max'],
                'step' => $slider['step'],
            ];
        }

        return $bounds;
    }

    /**
     * @param  array{memory: int, cpu: int, disk: int}  $vector
     * @param  list<array<string, mixed>>  $nodes
     */
    private function vectorFitsAnyNode(array $vector, array $nodes): bool
    {
        return collect($nodes)->contains(
            fn (array $node): bool => $this->vectorFitsNode($vector, $node)
        );
    }

    /**
     * @param  array{memory: int, cpu: int, disk: int}  $vector
     */
    private function vectorFitsNode(array $vector, array $node): bool
    {
        return $vector['memory'] <= $node['available']['memory']
            && $vector['cpu'] <= $node['available']['cpu']
            && $vector['disk'] <= $node['available']['disk'];
    }

    private function snapDown(int $value, int $minimum, int $step): int
    {
        if ($value < $minimum) {
            return $minimum - 1;
        }

        return $minimum + intdiv($value - $minimum, $step) * $step;
    }
}
