<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Drill\DrillReportStoreInterface;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;
use Vortos\Backup\Runtime\CronDueEvaluator;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;

/**
 * Holds the live system to its declared recovery objectives — continuously, not once a week.
 *
 * WHY THIS EXISTS (FB-66). The objectives used to feed only the DR runbook's text. A point-in-time
 * drill over its RTO was recorded as a success, and nothing compared the base-backup cadence with the
 * rate WAL piles up behind it. Measured in production on 2026-09-13: replay cost 0.40 s per segment
 * plus ~7.6 s fixed, segments arrive at 60 an hour, and the weekly base therefore put recovery past
 * the 1,800 s objective ~74 hours into every cycle — for four days a week — with every signal green.
 *
 * RPO is measured, not inferred: the age of the oldest WAL the cluster has written and no archive
 * holds. `archive_command` is the shipper, so archived means stored off the box.
 *
 * RTO is projected from evidence: the newest point-in-time drill's measured cost per replayed segment
 * and its fixed cost (everything that is not replay), applied to the segments archived since the
 * newest base backup. It is projected twice — now, and just before the next scheduled base backup —
 * because replay time only ever grows between bases, so the second number is the worst the current
 * cadence will reach. That one crossing the objective is the page: it is the configuration that is
 * wrong, and it is knowable days before any recovery would actually miss.
 *
 * Point-in-time recovery is a PostgreSQL capability here, so both measurements are for that engine.
 */
final class RecoveryObjectivesInspector
{
    /**
     * How much recent history the archive rate is measured over. A day spans every periodic job in a
     * normal week-day, so a quiet hour cannot make the projection optimistic.
     */
    public const ARCHIVE_RATE_WINDOW_SECONDS = 86_400;

    public function __construct(
        private readonly ?RecoveryObjectives $objectives,
        private readonly ArchiverStatusReaderInterface $archiver,
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly WalVolumeReadModelInterface $wal,
        private readonly DrillReportStoreInterface $drills,
        private readonly BackupScheduleRegistry $schedules,
        private readonly ClockInterface $clock,
        private readonly string $environment,
        private readonly CronDueEvaluator $evaluator = new CronDueEvaluator(),
    ) {}

    /** The catalog environment measured — 'production', not APP_ENV. */
    public function environment(): string
    {
        return $this->environment;
    }

    public function recoveryPoint(): RecoveryPointAssessment
    {
        if ($this->objectives === null) {
            return new RecoveryPointAssessment(ObjectiveStatus::Undeclared, null, null, null, 'no recovery objectives declared');
        }

        $objective = $this->objectives->rpoSeconds;

        try {
            $status = $this->archiver->read();
        } catch (Throwable $e) {
            return new RecoveryPointAssessment(
                ObjectiveStatus::Indeterminate,
                $objective,
                null,
                null,
                'cannot read the archiver state: ' . $e->getMessage(),
            );
        }

        if ($status->currentWal === null) {
            return new RecoveryPointAssessment(
                ObjectiveStatus::Indeterminate,
                $objective,
                null,
                $status,
                'the server is in recovery; archiving is measured on the primary',
            );
        }

        if ($status->lastArchivedAt === null) {
            return new RecoveryPointAssessment(
                ObjectiveStatus::Breached,
                $objective,
                null,
                $status,
                'no WAL segment has ever been archived — a loss of this cluster loses everything since its last base backup',
            );
        }

        if (!$status->hasUnarchivedWal()) {
            return new RecoveryPointAssessment(ObjectiveStatus::Met, $objective, 0, $status, 'every written WAL segment is archived');
        }

        $exposure = max(0, $this->clock->now()->getTimestamp() - $status->lastArchivedAt->getTimestamp());
        $breached = $exposure > $objective;

        return new RecoveryPointAssessment(
            $breached ? ObjectiveStatus::Breached : ObjectiveStatus::Met,
            $objective,
            $exposure,
            $status,
            sprintf(
                '%ds of WAL not yet archived (objective %ds)%s',
                $exposure,
                $objective,
                $status->isFailing() ? '; archive_command is failing' : '',
            ),
        );
    }

    public function recoveryTime(): RecoveryTimeAssessment
    {
        if ($this->objectives === null) {
            return new RecoveryTimeAssessment(ObjectiveStatus::Undeclared, null, 'no recovery objectives declared');
        }

        $objective = $this->objectives->rtoSeconds;

        try {
            $anchor = $this->catalog->latestOfKind(DatabaseEngine::Postgres, $this->environment, [BackupKind::PhysicalBase]);
            $report = $this->drills->latestOfKind(DatabaseEngine::Postgres->value, $this->environment, BackupKind::PhysicalBase);
        } catch (Throwable $e) {
            return new RecoveryTimeAssessment(ObjectiveStatus::Indeterminate, $objective, 'cannot read the catalog: ' . $e->getMessage());
        }

        if ($anchor === null) {
            return new RecoveryTimeAssessment(
                ObjectiveStatus::Indeterminate,
                $objective,
                'no physical base backup exists, so there is no point-in-time recovery to time',
            );
        }

        $cost = $report !== null ? $this->replayCost($report) : null;

        if ($cost === null) {
            return new RecoveryTimeAssessment(
                ObjectiveStatus::Indeterminate,
                $objective,
                $report === null
                    ? 'no point-in-time drill has run, so replay cost has never been measured'
                    : sprintf('the newest point-in-time drill (%s, %s) carries no replay measurement', $report->id, $report->outcome->value),
                anchorAt: $anchor->createdAt,
            );
        }

        [$msPerSegment, $fixedMs] = $cost;
        $now = $this->clock->now();

        try {
            $sinceAnchor = $this->wal->walVolumeSince(DatabaseEngine::Postgres, $this->environment, $anchor->createdAt)['segments'];
            $recent = $this->wal->walVolumeSince(
                DatabaseEngine::Postgres,
                $this->environment,
                $now->modify('-' . self::ARCHIVE_RATE_WINDOW_SECONDS . ' seconds'),
            )['segments'];
        } catch (Throwable $e) {
            return new RecoveryTimeAssessment(ObjectiveStatus::Indeterminate, $objective, 'cannot read archived WAL volume: ' . $e->getMessage());
        }

        $segmentsPerSecond = $recent / self::ARCHIVE_RATE_WINDOW_SECONDS;
        $projectedMs = $fixedMs + $msPerSegment * $sinceAnchor;
        $objectiveMs = (float) $this->objectives->rtoMilliseconds();

        $nextAnchorAt = $this->nextAnchorAfter($now);
        $projectedAtNextAnchorMs = $nextAnchorAt === null
            ? null
            : $projectedMs + $msPerSegment * $segmentsPerSecond * ($nextAnchorAt->getTimestamp() - $now->getTimestamp());

        $growthMsPerSecond = $msPerSegment * $segmentsPerSecond;
        $breachAt = match (true) {
            $projectedMs > $objectiveMs => $now,
            // Remaining headroom (ms of recovery) over growth (ms of recovery per wall-clock second).
            $growthMsPerSecond > 0.0 => $now->modify('+' . (int) ceil(($objectiveMs - $projectedMs) / $growthMsPerSecond) . ' seconds'),
            default => null,
        };

        $status = match (true) {
            $projectedMs > $objectiveMs => ObjectiveStatus::Breached,
            // No base backup is scheduled at all: the chain never resets, so any growth reaches the
            // objective eventually.
            $nextAnchorAt === null => $breachAt !== null ? ObjectiveStatus::AtRisk : ObjectiveStatus::Met,
            $projectedAtNextAnchorMs > $objectiveMs => ObjectiveStatus::AtRisk,
            default => ObjectiveStatus::Met,
        };

        return new RecoveryTimeAssessment(
            $status,
            $objective,
            match ($status) {
                ObjectiveStatus::Breached => sprintf('a point-in-time recovery now would take ~%ds against an objective of %ds', (int) round($projectedMs / 1000), $objective),
                ObjectiveStatus::AtRisk => $nextAnchorAt === null
                    ? sprintf('no base backup is scheduled, so replay grows without bound and crosses the %ds objective at %s', $objective, $breachAt?->format(DATE_ATOM))
                    : sprintf(
                        'the base backup cadence is too slow for the write rate: recovery reaches ~%ds before the next base at %s, crossing the %ds objective at %s',
                        (int) round((float) $projectedAtNextAnchorMs / 1000),
                        $nextAnchorAt->format(DATE_ATOM),
                        $objective,
                        $breachAt?->format(DATE_ATOM),
                    ),
                default => sprintf('recovery stays within %ds through the next base backup', $objective),
            },
            projectedSeconds: (int) round($projectedMs / 1000),
            projectedAtNextAnchorSeconds: $projectedAtNextAnchorMs !== null ? (int) round($projectedAtNextAnchorMs / 1000) : null,
            segmentsSinceAnchor: $sinceAnchor,
            segmentsPerHour: $segmentsPerSecond * 3600,
            replayMsPerSegment: $msPerSegment,
            fixedMs: (int) round($fixedMs),
            anchorAt: $anchor->createdAt,
            nextAnchorAt: $nextAnchorAt,
            // Only when it will actually happen. A crossing time that the next base backup pre-empts
            // is not a prediction, and printing one on a healthy system invites someone to act on it.
            breachAt: $status->pages() ? $breachAt : null,
            evidenceDrillId: $report->id,
            evidenceDrilledAt: $report->startedAt,
        );
    }

    /**
     * Measured replay cost from a drill: milliseconds per replayed segment, and the fixed cost of
     * everything else (provisioning, laying down the base, invariants).
     *
     * Only a drill that restored AND carries a passing replay measurement counts. A failed drill's
     * timings describe how long it took to fail, which says nothing about how long a recovery takes.
     *
     * @return array{float, float}|null
     */
    private function replayCost(DrillReport $report): ?array
    {
        if (!$report->restored()) {
            return null;
        }

        foreach ($report->invariants as $invariant) {
            if ($invariant->name !== 'wal_replayed' || !$invariant->passed) {
                continue;
            }

            $segments = PitrRecoveryOutcome::segmentsFromSummary($invariant->detail);
            $recoveryMs = PitrRecoveryOutcome::recoveryMsFromSummary($invariant->detail);

            if ($segments === null || $segments < 1 || $recoveryMs === null) {
                return null;
            }

            return [$recoveryMs / $segments, (float) max(0, $report->rtoMs - $recoveryMs)];
        }

        return null;
    }

    /** The earliest upcoming physical base backup across the declared schedules, or null when none is scheduled. */
    private function nextAnchorAfter(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $next = null;

        foreach ($this->schedules->all() as $schedule) {
            if ($schedule->type !== BackupScheduleType::Backup || $schedule->kind !== BackupKind::PhysicalBase) {
                continue;
            }

            $due = $this->evaluator->nextDueAfter($schedule->cron, $now);
            $next = $next === null || $due < $next ? $due : $next;
        }

        return $next;
    }
}
