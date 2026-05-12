<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Decorates CommandBusInterface to record per-command metrics.
 *
 * ## Metrics recorded
 *
 *   vortos_cqrs_commands_total{command}          — counter (all dispatches)
 *   vortos_cqrs_command_duration_ms{command}     — histogram (execution time)
 *   vortos_cqrs_command_failures_total{command}  — counter (exceptions only)
 *
 * ## Label value
 *
 *   'command' uses the short class name (e.g. 'RegisterUser'), not the FQCN.
 *   This keeps cardinality bounded — one label value per command type, not per instance.
 */
final class CqrsMetricsDecorator implements CommandBusInterface
{
    public function __construct(
        private readonly CommandBusInterface $inner,
        private readonly FrameworkTelemetry $telemetry,
    ) {}

    public function dispatch(CommandInterface $command): void
    {
        $commandName = substr(strrchr(get_class($command), '\\') ?: get_class($command), 1);
        $start       = hrtime(true);

        $labels = FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Command, $commandName));
        $this->telemetry->increment(ObservabilityModule::Cqrs, FrameworkMetric::CqrsCommandsTotal, $labels);

        try {
            $this->inner->dispatch($command);
        } catch (\Throwable $e) {
            $this->telemetry->increment(ObservabilityModule::Cqrs, FrameworkMetric::CqrsCommandFailuresTotal, $labels);
            throw $e;
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->telemetry->observe(ObservabilityModule::Cqrs, FrameworkMetric::CqrsCommandDurationMs, $labels, $durationMs);
        }
    }
}
