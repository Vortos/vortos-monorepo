<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\AutoInstrumentation\MessagingMetricDefinitions;

final class MessagingMetricDefinitionsTest extends TestCase
{
    public function test_defines_operational_outbox_and_dlq_gauges(): void
    {
        $definitions = [];
        foreach ((new MessagingMetricDefinitions())->definitions() as $definition) {
            $data = $definition->toArray();
            $definitions[$data['name']] = $data;
        }

        $this->assertSame(['transport', 'status'], $definitions['outbox_backlog_size']['label_names']);
        $this->assertSame(['transport'], $definitions['outbox_oldest_pending_age_seconds']['label_names']);
        $this->assertSame(['transport', 'event'], $definitions['dlq_backlog_size']['label_names']);
        $this->assertSame(['transport'], $definitions['dlq_oldest_failed_age_seconds']['label_names']);
    }
}
