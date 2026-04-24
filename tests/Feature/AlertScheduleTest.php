<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Paymenter\Extensions\Others\DynamicPterodactyl\DynamicPterodactyl;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AlertScheduleTest extends LaravelTestCase
{
    private function capacityAlertEvent(): object
    {
        (new DynamicPterodactyl)->boot();

        return collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'dynamic-pterodactyl:check-capacity-alerts');
    }

    public function test_capacity_alert_schedule_is_registered(): void
    {
        $event = $this->capacityAlertEvent();

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_capacity_alert_schedule_uses_without_overlapping(): void
    {
        $event = $this->capacityAlertEvent();

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
    }
}
