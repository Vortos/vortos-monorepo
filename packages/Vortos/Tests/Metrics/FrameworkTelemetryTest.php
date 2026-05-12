<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

final class FrameworkTelemetryTest extends TestCase
{
    public function test_uses_enum_metric_and_label_keys(): void
    {
        $counter = $this->createMock(CounterInterface::class);
        $counter->expects($this->once())->method('increment')->with(2.0);

        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->expects($this->once())
            ->method('counter')
            ->with('quota_consumed_total', [
                'quota' => 'checkout.orders',
                'period' => 'month',
            ])
            ->willReturn($counter);

        $telemetry = new FrameworkTelemetry($metrics);
        $telemetry->increment(
            ObservabilityModule::Auth,
            FrameworkMetric::QuotaConsumedTotal,
            FrameworkMetricLabels::of(
                MetricLabelValue::of(MetricLabel::Quota, 'checkout.orders'),
                MetricLabelValue::of(MetricLabel::Period, 'month'),
            ),
            2.0,
        );
    }

    public function test_disabled_module_does_not_touch_metrics_adapter(): void
    {
        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->expects($this->never())->method('counter');

        $telemetry = new FrameworkTelemetry($metrics, [ObservabilityModule::Auth]);
        $telemetry->increment(
            ObservabilityModule::Auth,
            FrameworkMetric::QuotaAllowedTotal,
            FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Quota, 'checkout.orders')),
        );
    }
}
