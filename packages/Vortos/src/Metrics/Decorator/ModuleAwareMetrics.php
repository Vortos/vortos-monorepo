<?php

declare(strict_types=1);

namespace Vortos\Metrics\Decorator;

use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Contract\ShutdownMetricsInterface;
use Vortos\Metrics\Instrument\NoOpCounter;
use Vortos\Metrics\Instrument\NoOpGauge;
use Vortos\Metrics\Instrument\NoOpHistogram;
use Vortos\Observability\Config\ObservabilityModule;

final class ModuleAwareMetrics implements MetricsInterface, FlushableMetricsInterface, ShutdownMetricsInterface
{
    private static CounterInterface $counter;
    private static GaugeInterface $gauge;
    private static HistogramInterface $histogram;

    /** @var array<string, true> */
    private array $disabled;

    /**
     * @param list<string|ObservabilityModule> $disabledModules
     */
    public function __construct(
        private readonly MetricsInterface $inner,
        array $disabledModules = [],
    ) {
        $this->disabled = [];
        foreach ($disabledModules as $module) {
            $value = $module instanceof ObservabilityModule ? $module->value : (string) $module;
            $this->disabled[$value] = true;
        }
    }

    public function counter(string $name, array $labels = []): CounterInterface
    {
        if ($this->isDisabled($name)) {
            return self::$counter ??= new NoOpCounter();
        }

        return $this->inner->counter($name, $labels);
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        if ($this->isDisabled($name)) {
            return self::$gauge ??= new NoOpGauge();
        }

        return $this->inner->gauge($name, $labels);
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        if ($this->isDisabled($name)) {
            return self::$histogram ??= new NoOpHistogram();
        }

        return $this->inner->histogram($name, $labels);
    }

    public function flush(): void
    {
        if ($this->inner instanceof FlushableMetricsInterface) {
            $this->inner->flush();
        }
    }

    public function shutdown(): void
    {
        if ($this->inner instanceof ShutdownMetricsInterface) {
            $this->inner->shutdown();
        }
    }

    private function isDisabled(string $metricName): bool
    {
        $module = $this->moduleForMetric($metricName);

        return $module !== null && isset($this->disabled[$module->value]);
    }

    private function moduleForMetric(string $metricName): ?ObservabilityModule
    {
        return match (true) {
            str_starts_with($metricName, 'http_') => ObservabilityModule::Http,
            str_starts_with($metricName, 'cqrs_') => ObservabilityModule::Cqrs,
            str_starts_with($metricName, 'messaging_'),
            str_starts_with($metricName, 'outbox_'),
            str_starts_with($metricName, 'dlq_') => ObservabilityModule::Messaging,
            str_starts_with($metricName, 'cache_') => ObservabilityModule::Cache,
            str_starts_with($metricName, 'persistence_'),
            str_starts_with($metricName, 'db_') => ObservabilityModule::Persistence,
            str_starts_with($metricName, 'security_') => ObservabilityModule::Security,
            str_starts_with($metricName, 'rate_limit_'),
            str_starts_with($metricName, 'quota_'),
            str_starts_with($metricName, 'feature_access_') => ObservabilityModule::Auth,
            str_starts_with($metricName, 'feature_flag_') => ObservabilityModule::FeatureFlags,
            default => null,
        };
    }
}
