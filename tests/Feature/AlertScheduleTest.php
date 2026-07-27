<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\DynamicPterodactyl;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\SchedulerHealthService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AlertScheduleTest extends LaravelTestCase
{
    private ?object $capacityAlertEvent = null;

    /** @var Collection<int, object> */
    private $events;

    protected function setUp(): void
    {
        parent::setUp();

        (new DynamicPterodactyl)->boot();

        $this->events = collect(app(Schedule::class)->events());
        $this->capacityAlertEvent = $this->events
            ->first(fn ($event) => $event->description === 'dynamic-pterodactyl:check-capacity-alerts');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
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

    public function test_lifecycle_task_types_have_independent_events(): void
    {
        $expected = [
            'dynamic-pterodactyl:expire-checkout-reservations' => '* * * * *',
            'dynamic-pterodactyl:expire-upgrade-reservations' => '* * * * *',
            'dynamic-pterodactyl:reconcile-paid-checkout-commitments' => '*/10 * * * *',
            'dynamic-pterodactyl:reconcile-paid-upgrades' => '*/10 * * * *',
            'dynamic-pterodactyl:check-capacity-alerts' => '*/5 * * * *',
            'dynamic-pterodactyl:monitor-scheduler-health' => '*/5 * * * *',
        ];

        foreach ($expected as $description => $expression) {
            $matching = $this->events->filter(
                fn ($event) => $event->description === $description
            );

            $this->assertCount(
                1,
                $matching,
                "Expected one independent scheduler event [{$description}]."
            );
            $event = $matching->first();
            $this->assertSame($expression, $event->expression);
            $this->assertTrue($event->withoutOverlapping);
        }
    }

    public function test_failed_expiry_callback_does_not_share_upgrade_callback(): void
    {
        $health = Mockery::mock(SchedulerHealthService::class);
        $health->shouldReceive('run')
            ->once()
            ->with(
                SchedulerHealthService::TASK_EXPIRE_CHECKOUT,
                Mockery::type(\Closure::class)
            )
            ->andThrow(new \RuntimeException('checkout cleanup failed'));
        $health->shouldReceive('run')
            ->once()
            ->with(
                SchedulerHealthService::TASK_EXPIRE_UPGRADES,
                Mockery::type(\Closure::class)
            )
            ->andReturn(0);
        $this->app->instance(SchedulerHealthService::class, $health);

        try {
            ($this->callbackFor(
                'dynamic-pterodactyl:expire-checkout-reservations'
            ))();
            $this->fail('Expected the checkout callback to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'checkout cleanup failed',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            0,
            ($this->callbackFor(
                'dynamic-pterodactyl:expire-upgrade-reservations'
            ))()
        );
    }

    private function callbackFor(string $description): \Closure
    {
        $event = $this->events->first(
            fn ($candidate) => $candidate->description === $description
        );
        $this->assertNotNull($event);
        $property = new \ReflectionProperty($event, 'callback');
        $property->setAccessible(true);
        $callback = $property->getValue($event);
        $this->assertInstanceOf(\Closure::class, $callback);

        return $callback;
    }
}
