<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertDeliveryLog;

class AlertDeliveryFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AlertDeliveryLog $deliveryLog,
    ) {}
}
