<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Paymenter\Extensions\Others\DynamicPterodactyl\DynamicPterodactyl;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AlertScheduleTest extends LaravelTestCase
{
    private ?object $capacityAlertEvent = null;

    protected function setUp(): void
    {
        parent::setUp();

        (new DynamicPterodactyl)->boot();

        $this->capacityAlertEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'dynamic-pterodactyl:check-capacity-alerts');
    }

    public function test_capacity_alert_schedule_is_registered(): void
    {
        $this->assertNotNull($this->capacityAlertEvent);
        $this->assertSame('*/5 * * * *', $this->capacityAlertEvent->expression);
    }

    public function test_capacity_alert_schedule_uses_without_overlapping(): void
    {
        $this->assertNotNull($this->capacityAlertEvent);
        $this->assertTrue($this->capacityAlertEvent->withoutOverlapping);
    }
}
