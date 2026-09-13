<?php

declare(strict_types=1);

namespace Vortos\Backup\Observability;

use Throwable;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\DR\ObjectiveStatus;
use Vortos\Backup\DR\RecoveryObjectivesInspector;
use Vortos\Metrics\Contract\MetricsCollectorInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Publishes the measured recovery point exposure and projected recovery time next to their
 * objectives, so the trend is graphable. The probes page; these explain the page.
 *
 * A measurement that could not be made is not published — an absent series, never a zero, because
 * zero exposure reads as "perfectly archived".
 */
final class RecoveryObjectivesCollector implements MetricsCollectorInterface
{
    public function __construct(
        private readonly RecoveryObjectivesInspector $inspector,
        private readonly ?FrameworkTelemetry $telemetry = null,
    ) {}

    public function collect(): void
    {
        if ($this->telemetry === null) {
            return;
        }

        $labels = FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Engine, DatabaseEngine::Postgres->value),
            MetricLabelValue::of(MetricLabel::Environment, $this->inspector->environment()),
        );

        try {
            $point = $this->inspector->recoveryPoint();
            if ($point->status !== ObjectiveStatus::Undeclared) {
                $this->gauge(FrameworkMetric::BackupRpoObjectiveSeconds, $labels, $point->objectiveSeconds);
                $this->gauge(FrameworkMetric::BackupRpoExposureSeconds, $labels, $point->exposureSeconds);
            }
        } catch (Throwable) {
            // One failed read must not take down the metrics endpoint or the RTO series below.
        }

        try {
            $time = $this->inspector->recoveryTime();
            if ($time->status !== ObjectiveStatus::Undeclared) {
                $this->gauge(FrameworkMetric::BackupRtoObjectiveSeconds, $labels, $time->objectiveSeconds);
                $this->gauge(FrameworkMetric::BackupRtoProjectedSeconds, $labels, $time->projectedSeconds);
                $this->gauge(FrameworkMetric::BackupRtoProjectedAtNextAnchorSeconds, $labels, $time->projectedAtNextAnchorSeconds);
            }
        } catch (Throwable) {
        }
    }

    private function gauge(FrameworkMetric $metric, FrameworkMetricLabels $labels, ?int $value): void
    {
        if ($value === null) {
            return;
        }

        $this->telemetry?->setGauge(ObservabilityModule::Backup, $metric, $labels, (float) $value);
    }
}
