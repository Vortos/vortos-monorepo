<?php

declare(strict_types=1);

namespace Vortos\Backup\Observability;

use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Metrics\Contract\MetricsCollectorInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Publishes how old the newest successful backup is.
 *
 * Backup alerting was previously push-only: BackupEventAlertSink fires when a backup RUNS and
 * FAILS. That cannot see the more dangerous case — a backup that stopped running at all, because
 * the worker died or the schedule was removed. Nothing fails, so nothing alerts, and the gap is
 * discovered at restore time, which is the worst possible moment to discover it.
 *
 * Age, not a boolean: "when did we last succeed" degrades gracefully into a threshold anyone can
 * reason about, and it keeps working across backup cadence changes.
 *
 * Implements {@see MetricsCollectorInterface}, so it is refreshed by whatever already drives the
 * operational collectors — the Prometheus scrape, or the framework's scheduled collect under push
 * adapters. It needs no driver of its own.
 */
final class BackupFreshnessCollector implements MetricsCollectorInterface
{
    /**
     * @param list<DatabaseEngine> $engines
     */
    public function __construct(
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly ClockInterface $clock,
        private readonly array $engines,
        private readonly string $environment,
        private readonly ?FrameworkTelemetry $telemetry = null,
    ) {}

    public function collect(): void
    {
        if ($this->telemetry === null) {
            return;
        }

        foreach ($this->engines as $engine) {
            try {
                $this->collectEngine($engine);
            } catch (Throwable) {
                // A catalog read failure must never break the metrics endpoint or the scheduled
                // collect for every other collector.
            }
        }
    }

    private function collectEngine(DatabaseEngine $engine): void
    {
        // Restorable kinds only. A wal_segment arrives roughly every sixty seconds, so an
        // unfiltered lookup would keep this gauge permanently green — and a dashboard that cannot
        // go red is indistinguishable from one nobody is watching.
        $latest = $this->catalog->latestOfKind(
            $engine,
            $this->environment,
            [BackupKind::LogicalFull, BackupKind::PhysicalBase, BackupKind::MongoArchive],
        );

        $labels = FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Engine, $engine->value),
            MetricLabelValue::of(MetricLabel::Environment, $this->environment),
        );

        // No backup has ever been recorded for this engine. Reporting age 0 would read as "just
        // backed up" — the exact inversion of the truth — so report presence separately and leave
        // age unset. An alert on backup_present == 0 covers the never-ran case.
        $this->telemetry?->setGauge(
            ObservabilityModule::Backup,
            FrameworkMetric::BackupPresent,
            $labels,
            $latest === null ? 0.0 : 1.0,
        );

        if ($latest === null) {
            return;
        }

        $ageSeconds = $this->clock->now()->getTimestamp() - $latest->createdAt->getTimestamp();

        $this->telemetry?->setGauge(
            ObservabilityModule::Backup,
            FrameworkMetric::BackupLastSuccessAgeSeconds,
            $labels,
            (float) max(0, $ageSeconds),
        );

        $this->telemetry?->setGauge(
            ObservabilityModule::Backup,
            FrameworkMetric::BackupLastSuccessSizeBytes,
            $labels,
            (float) $latest->sizeBytes,
        );
    }
}
