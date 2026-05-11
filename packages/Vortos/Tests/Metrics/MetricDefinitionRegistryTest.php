<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Definition\MetricType;
use Vortos\Metrics\Exception\MetricLabelMismatchException;
use Vortos\Metrics\Exception\MetricLabelValueException;
use Vortos\Metrics\Exception\MetricTypeMismatchException;

final class MetricDefinitionRegistryTest extends TestCase
{
    public function test_rejects_duplicate_metric_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MetricDefinitionRegistry([
            MetricDefinition::counter('orders_total', 'Total orders.'),
            MetricDefinition::counter('orders_total', 'Duplicate orders.'),
        ]);
    }

    public function test_requires_expected_metric_type(): void
    {
        $registry = new MetricDefinitionRegistry([
            MetricDefinition::counter('orders_total', 'Total orders.'),
        ]);

        $this->expectException(MetricTypeMismatchException::class);
        $registry->requireType('orders_total', MetricType::Gauge);
    }

    public function test_validates_exact_label_set_and_returns_definition_order(): void
    {
        $definition = MetricDefinition::counter('orders_total', 'Total orders.', ['tenant', 'channel']);
        $registry = new MetricDefinitionRegistry([$definition]);

        $labels = $registry->validateLabels($definition, ['channel' => 'web', 'tenant' => 'acme']);

        $this->assertSame(['tenant' => 'acme', 'channel' => 'web'], $labels);
    }

    public function test_rejects_missing_or_extra_labels(): void
    {
        $definition = MetricDefinition::counter('orders_total', 'Total orders.', ['tenant']);
        $registry = new MetricDefinitionRegistry([$definition]);

        $this->expectException(MetricLabelMismatchException::class);
        $registry->validateLabels($definition, ['tenant' => 'acme', 'user_id' => '1']);
    }

    public function test_rejects_label_values_that_can_break_text_protocols(): void
    {
        $definition = MetricDefinition::counter('orders_total', 'Total orders.', ['tenant']);
        $registry = new MetricDefinitionRegistry([$definition]);

        $this->expectException(MetricLabelValueException::class);
        $registry->validateLabels($definition, ['tenant' => "acme\norders_total:1|c"]);
    }
}
