<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

class AllocationSelectionService
{
    /**
     * Select required ports in caller-provided mapping order, then fill any
     * remaining slots deterministically by allocation ID.
     *
     * A product mapping identifies a port number, not an IP. When the same port
     * exists on several free IPs, the lowest allocation ID is the canonical
     * choice. Both quoting and reservation placement call this service, so they
     * evaluate the same deterministic allocation set.
     *
     * @param  list<array{id: int, ip: string, port: int, ip_in_use?: bool}>  $available
     * @param  list<int>  $requiredPorts
     * @param  list<array{from: int, to: int}>  $allowedPortRanges
     * @return list<array{id: int, ip: string, port: int, ip_in_use?: bool}>|null
     */
    public function select(
        array $available,
        int $allocationCount,
        array $requiredPorts = [],
        array $allowedPortRanges = [],
        bool $dedicatedIp = false
    ): ?array {
        if ($allocationCount < 1) {
            throw new \InvalidArgumentException('At least one allocation is required.');
        }

        foreach ($requiredPorts as $requiredPort) {
            if (! is_int($requiredPort) || $requiredPort < 1 || $requiredPort > 65535) {
                throw new \InvalidArgumentException(
                    'Required Pterodactyl ports must be integer values between 1 and 65535.'
                );
            }
        }
        if (count(array_unique($requiredPorts)) !== count($requiredPorts)) {
            throw new \InvalidArgumentException(
                'A required Pterodactyl port cannot be requested more than once.'
            );
        }
        if (count($requiredPorts) > $allocationCount) {
            throw new \InvalidArgumentException(
                'Required ports cannot exceed the requested allocation count.'
            );
        }

        foreach ($available as $allocation) {
            if (
                ! is_int($allocation['id'] ?? null)
                || $allocation['id'] < 1
                || ! is_string($allocation['ip'] ?? null)
                || $allocation['ip'] === ''
                || ! is_int($allocation['port'] ?? null)
                || $allocation['port'] < 1
                || $allocation['port'] > 65535
            ) {
                return null;
            }
            if (
                $dedicatedIp
                && ! is_bool($allocation['ip_in_use'] ?? null)
            ) {
                return null;
            }
        }
        $allowedPortRanges = $this->validatedRanges($allowedPortRanges);

        usort(
            $available,
            fn (array $left, array $right): int =>
                $left['id'] <=> $right['id']
        );

        $ids = array_column($available, 'id');
        if (count(array_unique($ids)) !== count($ids)) {
            return null;
        }

        if ($dedicatedIp) {
            $candidates = [];
            $byIp = [];
            foreach ($available as $allocation) {
                $key = $this->canonicalIp($allocation['ip']);
                $byIp[$key] ??= [
                    'in_use' => false,
                    'allocations' => [],
                ];
                $byIp[$key]['in_use'] = $byIp[$key]['in_use']
                    || $allocation['ip_in_use'];
                $byIp[$key]['allocations'][] = $allocation;
            }
            foreach ($byIp as $ipGroup) {
                if ($ipGroup['in_use']) {
                    continue;
                }
                $selected = $this->selectFromPool(
                    $ipGroup['allocations'],
                    $allocationCount,
                    $requiredPorts,
                    $allowedPortRanges
                );
                if ($selected !== null) {
                    $candidates[] = $selected;
                }
            }
            usort(
                $candidates,
                fn (array $left, array $right): int =>
                    min(array_column($left, 'id'))
                    <=> min(array_column($right, 'id'))
            );

            return $candidates[0] ?? null;
        }

        return $this->selectFromPool(
            $available,
            $allocationCount,
            $requiredPorts,
            $allowedPortRanges
        );
    }

    /**
     * @param  list<array{id: int, ip: string, port: int, ip_in_use?: bool}>  $available
     * @param  list<int>  $requiredPorts
     * @param  list<array{from: int, to: int}>  $allowedPortRanges
     * @return list<array{id: int, ip: string, port: int, ip_in_use?: bool}>|null
     */
    private function selectFromPool(
        array $available,
        int $allocationCount,
        array $requiredPorts,
        array $allowedPortRanges
    ): ?array {
        if (count($available) < $allocationCount) {
            return null;
        }

        $selected = [];
        $selectedIds = [];
        if ($allowedPortRanges !== []) {
            $primary = collect($available)->first(
                fn (array $allocation): bool => $this->portAllowed(
                    $allocation['port'],
                    $allowedPortRanges
                )
            );
            if (! is_array($primary)) {
                return null;
            }
            $selected[] = $primary;
            $selectedIds[$primary['id']] = true;
        }
        foreach ($requiredPorts as $requiredPort) {
            if (collect($selected)->contains(
                fn (array $allocation): bool =>
                    $allocation['port'] === $requiredPort
            )) {
                continue;
            }
            $matches = array_values(array_filter(
                $available,
                fn (array $allocation): bool =>
                    $allocation['port'] === $requiredPort
                    && ! isset($selectedIds[$allocation['id']])
            ));
            if ($matches === []) {
                return null;
            }

            $selected[] = $matches[0];
            $selectedIds[$matches[0]['id']] = true;
        }

        foreach ($available as $allocation) {
            if (count($selected) >= $allocationCount) {
                break;
            }
            if (isset($selectedIds[$allocation['id']])) {
                continue;
            }

            $selected[] = $allocation;
            $selectedIds[$allocation['id']] = true;
        }

        return count($selected) === $allocationCount ? $selected : null;
    }

    /**
     * @param  list<array{from: int, to: int}>  $ranges
     * @return list<array{from: int, to: int}>
     */
    private function validatedRanges(array $ranges): array
    {
        foreach ($ranges as $range) {
            if (
                ! is_array($range)
                || ! is_int($range['from'] ?? null)
                || ! is_int($range['to'] ?? null)
                || $range['from'] < 1
                || $range['to'] > 65535
                || $range['from'] > $range['to']
            ) {
                throw new \InvalidArgumentException(
                    'Pterodactyl port ranges must be inclusive integer bounds between 1 and 65535.'
                );
            }
        }

        return array_values($ranges);
    }

    /**
     * @param  list<array{from: int, to: int}>  $ranges
     */
    private function portAllowed(int $port, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($port >= $range['from'] && $port <= $range['to']) {
                return true;
            }
        }

        return false;
    }

    private function canonicalIp(string $ip): string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false
            ? strtolower(trim($ip))
            : bin2hex($packed);
    }
}
