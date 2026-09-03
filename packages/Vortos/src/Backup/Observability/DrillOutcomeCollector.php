<?php

declare(strict_types=1);

namespace Vortos\Backup\Observability;

use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Drill\DrillReportStoreInterface;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;
use Vortos\Metrics\Contract\MetricsCollectorInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Publishes the state of each restore path: did its last drill pass, how long it took, how long ago
 * it ran, and — for the point-in-time path — how much log it actually replayed.
 *
 * WHY PULL, FROM THE REPORT TABLE, rather than gauges set as the drill finishes. A drill is a weekly
 * event inside a process that then goes back to sleep; a gauge written at that instant is scraped
 * once, if the timing is lucky, and reads as absent for the rest of the week. Reading the stored
 * report means the series is continuously true — "the last point-in-time drill passed, 4 days ago"
 * — which is the shape an alert can actually be written against.
 *
 * PER KIND, and that is the point of the collector. Once an installation drills two restore paths on
 * two cadences, "the last drill" is not one fact: a daily logical drill is always the more recent
 * row, so an unlabelled series would show green while a weekly point-in-time drill had been failing
 * for a month. The kind label is what keeps the WAL chain's health visible on its own.
 *
 * THE ALERT WORTH WRITING is on {@see FrameworkMetric::BackupDrillRestorePointAgeSeconds}, which is
 * the age of the newest base backup — the anchor a real recovery would start from. It rises
 * continuously between base backups and is the leading indicator for the point-in-time drill: a
 * chain that has grown too long to replay shows up here days before it shows up as a drill that
 * exceeded its segment budget.
 */
final class DrillOutcomeCollector implements MetricsCollectorInterface
{
    /**
     * @param list<DatabaseEngine> $engines
     * @param list<BackupKind>     $kinds restore paths this installation drills
     */
    public function __construct(
        private readonly DrillReportStoreInterface $reports,
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly ClockInterface $clock,
        private readonly array $engines,
        private readonly string $environment,
        private readonly array $kinds = [BackupKind::LogicalFull, BackupKind::PhysicalBase],
        private readonly ?FrameworkTelemetry $telemetry = null,
    ) {}

    public function collect(): void
    {
        if ($this->telemetry === null) {
            return;
        }

        foreach ($this->engines as $engine) {
            foreach ($this->kinds as $kind) {
                try {
                    $this->collectKind($engine, $kind);
                } catch (Throwable) {
                    // One unreadable report must never take down the metrics endpoint, nor the
                    // collectors that run after this one.
                }
            }
        }
    }

    private function collectKind(DatabaseEngine $engine, BackupKind $kind): void
    {
        $labels = FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Engine, $engine->value),
            MetricLabelValue::of(MetricLabel::Environment, $this->environment),
            MetricLabelValue::of(MetricLabel::Kind, $kind->value),
        );

        if ($kind === BackupKind::PhysicalBase) {
            $this->collectRestorePointAge($engine, $labels);
        }

        $report = $this->reports->latestOfKind($engine->value, $this->environment, $kind);

        if ($report === null) {
            // Nothing emitted but the age, deliberately. Publishing outcome=0 for a drill that has
            // never run would page as a FAILING drill, when the true state is that this restore path
            // has never been proved — a different problem, caught by the age series being absent and
            // by the freshness collector.
            return;
        }

        $this->telemetry?->setGauge(
            ObservabilityModule::Backup,
            FrameworkMetric::BackupDrillLastOutcome,
            $labels,
            $report->passed() ? 1.0 : 0.0,
        );

        $this->telemetry?->setGauge(
            ObservabilityModule::Backup,
            FrameworkMetric::BackupDrillLastRtoMs,
            $labels,
            (float) $report->rtoMs,
        );

        // How long since this path was last proved. The signal that catches a drill which stopped
        // RUNNING rather than started failing — the failure mode no push-on-failure alert can see.
        $this->telemetry?->setGauge(
            ObservabilityModule::Backup,
            FrameworkMetric::BackupDrillLastAgeSeconds,
            $labels,
            (float) max(0, $this->clock->now()->getTimestamp() - $report->startedAt->getTimestamp()),
        );

        $segments = $this->segmentsReplayed($report);
        if ($segments !== null) {
            $this->telemetry?->setGauge(
                ObservabilityModule::Backup,
                FrameworkMetric::BackupDrillWalSegmentsReplayed,
                $labels,
                (float) $segments,
            );
        }
    }

    /**
     * Age of the newest physical base backup — the anchor a point-in-time recovery replays from.
     */
    private function collectRestorePointAge(DatabaseEngine $engine, FrameworkMetricLabels $labels): void
    {
        $base = $this->catalog->latestOfKind($engine, $this->environment, [BackupKind::PhysicalBase]);

        if ($base === null) {
            return;
        }

        $this->telemetry?->setGauge(
            ObservabilityModule::Backup,
            FrameworkMetric::BackupDrillRestorePointAgeSeconds,
            $labels,
            (float) max(0, $this->clock->now()->getTimestamp() - $base->createdAt->getTimestamp()),
        );
    }

    /**
     * Segments replayed, read back out of the drill's recorded evidence.
     *
     * The count lives in the `wal_replayed` invariant's detail rather than in a column of its own,
     * because the report table stores invariant results as JSON and this is one number on a weekly
     * row — a schema change to carry it would be cost with no benefit. The format is owned by
     * {@see PitrRecoveryOutcome}, which both writes and reads it, so the two cannot drift apart. A
     * detail that does not match yields null and the series is simply not emitted, rather than a
     * zero that would read as "replayed nothing".
     */
    private function segmentsReplayed(DrillReport $report): ?int
    {
        foreach ($report->invariants as $invariant) {
            if ($invariant->name !== 'wal_replayed' || !$invariant->passed) {
                continue;
            }

            return PitrRecoveryOutcome::segmentsFromSummary($invariant->detail);
        }

        return null;
    }
}
