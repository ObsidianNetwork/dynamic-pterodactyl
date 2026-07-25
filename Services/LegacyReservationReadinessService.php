<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

/**
 * Container-facing wrapper around the destination-tree readiness hook.
 *
 * The hook itself is an anonymous class loaded from disk on every invocation.
 * Upload updates can therefore execute the new implementation even when this
 * named class was already loaded from the previous extension version.
 */
class LegacyReservationReadinessService
{
    /**
     * @return list<array{
     *     reservation_id: int,
     *     service_id: int|null,
     *     purpose: string,
     *     missing: list<string>
     * }>
     */
    public function blockers(): array
    {
        return $this->gate()->blockers();
    }

    public function assertReady(): void
    {
        $this->gate()->assertReady();
    }

    private function gate(): object
    {
        $gate = require dirname(__DIR__).'/migration-readiness.php';
        if (! is_object($gate) || ! method_exists($gate, 'assertReady')) {
            throw new \RuntimeException(
                'Dynamic Pterodactyl migration readiness hook is invalid.'
            );
        }

        return $gate;
    }
}
