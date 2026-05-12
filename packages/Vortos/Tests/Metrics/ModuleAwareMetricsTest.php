<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Decorator\ModuleAwareMetrics;
use Vortos\Metrics\Instrument\NoOpCounter;
use Vortos\Observability\Config\ObservabilityModule;

final class ModuleAwareMetricsTest extends TestCase
{
    public function test_disabled_module_returns_noop_without_touching_inner_metrics(): void
    {
        $inner = $this->createMock(MetricsInterface::class);
        $inner->expects($this->never())->method('counter');

        $metrics = new ModuleAwareMetrics($inner, [ObservabilityModule::Auth]);
        $counter = $metrics->counter('quota_allowed_total', [
            'quota' => 'checkout.orders',
            'bucket' => 'user',
            'period' => 'month',
            'controller' => 'App.Controller.CheckoutController',
        ]);

        $this->assertInstanceOf(NoOpCounter::class, $counter);
    }

    public function test_enabled_module_delegates_to_inner_metrics(): void
    {
        $counter = $this->createMock(CounterInterface::class);
        $inner = $this->createMock(MetricsInterface::class);
        $inner->expects($this->once())
            ->method('counter')
            ->with('quota_allowed_total')
            ->willReturn($counter);

        $metrics = new ModuleAwareMetrics($inner, [ObservabilityModule::Cache]);

        $this->assertSame($counter, $metrics->counter('quota_allowed_total', [
            'quota' => 'checkout.orders',
            'bucket' => 'user',
            'period' => 'month',
            'controller' => 'App.Controller.CheckoutController',
        ]));
    }

    public function test_unknown_application_metric_delegates(): void
    {
        $gauge = $this->createMock(GaugeInterface::class);
        $histogram = $this->createMock(HistogramInterface::class);
        $inner = $this->createMock(MetricsInterface::class);
        $inner->expects($this->once())->method('gauge')->with('orders_open')->willReturn($gauge);
        $inner->expects($this->once())->method('histogram')->with('checkout_duration_ms')->willReturn($histogram);

        $metrics = new ModuleAwareMetrics($inner, [ObservabilityModule::Auth]);

        $this->assertSame($gauge, $metrics->gauge('orders_open'));
        $this->assertSame($histogram, $metrics->histogram('checkout_duration_ms'));
    }
}
