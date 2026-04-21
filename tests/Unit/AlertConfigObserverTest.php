<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\Observers\AlertConfigObserver;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AlertConfigObserverTest extends LaravelTestCase
{
    private $mockAudit;

    private AlertConfigObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockAudit = Mockery::mock(AuditLogService::class);
        $this->observer = new AlertConfigObserver($this->mockAudit);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_created_logs_audit_entry(): void
    {
        $config = new AlertConfig([
            'location_id' => 1,
            'location_name' => 'US East',
            'is_active' => true,
            'memory_warning_threshold' => 80,
            'memory_critical_threshold' => 90,
        ]);
        $config->id = 10;

        $this->mockAudit->shouldReceive('log')
            ->once()
            ->with('created', 'alert_config', 10, [
                'location_id' => 1,
                'location_name' => 'US East',
                'is_active' => true,
                'memory_warning_threshold' => 80,
                'memory_critical_threshold' => 90,
            ])
            ->andReturn(1);

        $this->observer->created($config);

        $this->addToAssertionCount(1);
    }

    public function test_created_redacts_webhook_url(): void
    {
        $config = new AlertConfig([
            'location_id' => 1,
            'webhook_url' => 'https://hooks.slack.com/services/SECRET',
        ]);
        $config->id = 10;

        $this->mockAudit->shouldReceive('log')
            ->once()
            ->with('created', 'alert_config', 10, Mockery::on(function ($attrs) {
                return $attrs['webhook_url'] === '[REDACTED]'
                    && $attrs['location_id'] === 1;
            }))
            ->andReturn(1);

        $this->observer->created($config);

        $this->addToAssertionCount(1);
    }

    public function test_updated_logs_changes(): void
    {
        /** @var AlertConfig&\Mockery\MockInterface $config */
        $config = Mockery::mock(AlertConfig::class)->makePartial();
        $config->id = 10;
        $config->shouldReceive('getChanges')->andReturn(['is_active' => false]);
        $config->shouldReceive('getOriginal')->andReturn([
            'is_active' => true,
            'memory_warning_threshold' => 80,
            'location_id' => 1,
        ]);

        $this->mockAudit->shouldReceive('log')
            ->once()
            ->with(
                'updated',
                'alert_config',
                10,
                ['is_active' => false],
                ['is_active' => true]
            )
            ->andReturn(1);

        $this->observer->updated($config);

        $this->addToAssertionCount(1);
    }

    public function test_updated_redacts_webhook_url_on_both_sides(): void
    {
        /** @var AlertConfig&\Mockery\MockInterface $config */
        $config = Mockery::mock(AlertConfig::class)->makePartial();
        $config->id = 10;
        $config->shouldReceive('getChanges')->andReturn([
            'webhook_url' => 'https://hooks.slack.com/services/NEW_SECRET',
        ]);
        $config->shouldReceive('getOriginal')->andReturn([
            'webhook_url' => 'https://hooks.slack.com/services/OLD_SECRET',
            'is_active' => true,
        ]);

        $this->mockAudit->shouldReceive('log')
            ->once()
            ->with(
                'updated',
                'alert_config',
                10,
                ['webhook_url' => '[REDACTED]'],
                ['webhook_url' => '[REDACTED]']
            )
            ->andReturn(1);

        $this->observer->updated($config);

        $this->addToAssertionCount(1);
    }

    public function test_deleted_logs_audit_entry(): void
    {
        $config = new AlertConfig([
            'location_id' => 1,
            'location_name' => 'US East',
            'is_active' => true,
            'memory_warning_threshold' => 80,
        ]);
        $config->id = 10;

        $this->mockAudit->shouldReceive('log')
            ->once()
            ->with('deleted', 'alert_config', 10, Mockery::on(function ($attrs) {
                return $attrs['location_id'] === 1
                    && $attrs['location_name'] === 'US East'
                    && $attrs['is_active'] === true
                    && $attrs['memory_warning_threshold'] === 80
                    && ! array_key_exists('id', $attrs)
                    && ! array_key_exists('created_at', $attrs)
                    && ! array_key_exists('updated_at', $attrs);
            }))
            ->andReturn(1);

        $this->observer->deleted($config);

        $this->addToAssertionCount(1);
    }

    public function test_deleted_redacts_webhook_url(): void
    {
        $config = new AlertConfig([
            'location_id' => 1,
            'webhook_url' => 'https://hooks.slack.com/services/SECRET',
        ]);
        $config->id = 10;

        $this->mockAudit->shouldReceive('log')
            ->once()
            ->with('deleted', 'alert_config', 10, Mockery::on(function ($attrs) {
                return $attrs['webhook_url'] === '[REDACTED]'
                    && $attrs['location_id'] === 1;
            }))
            ->andReturn(1);

        $this->observer->deleted($config);

        $this->addToAssertionCount(1);
    }
}
