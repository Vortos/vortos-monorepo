<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Definition\MetricDefinition;

final class MetricDefinitionTest extends TestCase
{
    public function test_counter_requires_non_empty_help(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MetricDefinition::counter('orders_total', '');
    }

    public function test_metric_name_must_be_portable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MetricDefinition::counter('orders:total', 'Total orders.');
    }

    public function test_help_rejects_control_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MetricDefinition::counter('orders_total', "Orders\nTotal");
    }

    public function test_label_names_must_be_unique(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MetricDefinition::counter('orders_total', 'Total orders.', ['tenant', 'tenant']);
    }

    public function test_histogram_buckets_must_be_strictly_increasing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MetricDefinition::histogram('duration_ms', 'Duration.', [], [10, 5]);
    }

    public function test_round_trips_through_array(): void
    {
        $definition = MetricDefinition::histogram('duration_ms', 'Duration.', ['route'], [5, 10, 50]);
        $restored = MetricDefinition::fromArray($definition->toArray());

        $this->assertSame($definition->type, $restored->type);
        $this->assertSame($definition->name, $restored->name);
        $this->assertSame($definition->help, $restored->help);
        $this->assertSame($definition->labelNames, $restored->labelNames);
        $this->assertSame($definition->buckets, $restored->buckets);
    }
}
