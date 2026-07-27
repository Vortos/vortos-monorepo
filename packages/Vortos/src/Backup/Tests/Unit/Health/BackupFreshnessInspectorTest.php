<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Health;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Health\BackupFreshnessInspector;
use Vortos\Backup\Health\BackupFreshnessStatus;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;

final class BackupFreshnessInspectorTest extends TestCase
{
    /** A backup taken within the cadence window is fresh. */
    public function test_recent_backup_is_fresh(): void
    {
        $inspector = $this->inspector(
            lastBackupAt: '2026-07-22 09:00:00',
            now: '2026-07-22 12:00:00',
        );

        $results = $inspector->inspect();

        $this->assertCount(1, $results);
        $this->assertSame(BackupFreshnessStatus::Fresh, $results[0]->status);
        $this->assertSame(10800, $results[0]->ageSeconds);
    }

    /**
     * THE REGRESSION THAT MATTERS. This is the exact production incident: a 6-hourly cadence whose
     * last catalogued backup is 2026-07-07 12:00 and "now" is 2026-07-22. The old system had no way
     * to notice, because nothing ever failed. This must come back Stale.
     */
    public function test_the_2026_07_incident_is_detected(): void
    {
        $inspector = $this->inspector(
            lastBackupAt: '2026-07-07 12:00:00',
            now: '2026-07-22 12:00:00',
        );

        $results = $inspector->inspect();

        $this->assertSame(BackupFreshnessStatus::Stale, $results[0]->status);
        $this->assertSame(15 * 86400, $results[0]->ageSeconds);
        $this->assertStringContainsString('backup cadence has stopped', $results[0]->describe());
        $this->assertCount(1, $inspector->breaches());
    }

    /** An environment that has never been backed up is its own distinct condition, not "stale". */
    public function test_no_backup_at_all_is_never_run(): void
    {
        $inspector = $this->inspector(lastBackupAt: null, now: '2026-07-22 12:00:00');

        $results = $inspector->inspect();

        $this->assertSame(BackupFreshnessStatus::NeverRun, $results[0]->status);
        $this->assertNull($results[0]->ageSeconds);
        $this->assertCount(1, $inspector->breaches());
    }

    /** One skipped run must not page: 6h cadence × 2.5 tolerance = 15h threshold. */
    public function test_a_single_missed_run_is_still_fresh(): void
    {
        $inspector = $this->inspector(
            lastBackupAt: '2026-07-22 00:00:00',
            now: '2026-07-22 12:00:00', // 12h — one missed 6h window, inside the 15h threshold
        );

        $this->assertSame(BackupFreshnessStatus::Fresh, $inspector->inspect()[0]->status);
    }

    public function test_threshold_derives_from_the_declared_cadence(): void
    {
        $inspector = $this->inspector(lastBackupAt: null, now: '2026-07-22 12:00:00');

        // 6h cadence × 2.5 = 15h.
        $this->assertSame(54000, $inspector->thresholdFor('0 */6 * * *'));
        // Hourly cadence tightens the threshold automatically — no second knob to forget.
        $this->assertSame(9000, $inspector->thresholdFor('0 * * * *'));
    }

    /** A cadence slower than the 48h measurement window falls back rather than alerting constantly. */
    public function test_unmeasurably_slow_cadence_uses_the_conservative_fallback(): void
    {
        $inspector = $this->inspector(lastBackupAt: null, now: '2026-07-22 12:00:00');

        $this->assertSame(172800, $inspector->thresholdFor('0 4 1 * *')); // monthly
    }

    /** Retention and drill schedules are not backups and must not be reported as stale targets. */
    public function test_only_backup_type_schedules_are_inspected(): void
    {
        $registry = new BackupScheduleRegistry([
            new BackupSchedule('platform-backup', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'production', '0 */6 * * *', BackupScheduleType::Backup),
            new BackupSchedule('platform-retention', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'production', '0 3 * * *', BackupScheduleType::Retention),
            new BackupSchedule('platform-drill', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'production', '0 4 * * 0', BackupScheduleType::Drill),
        ]);

        $inspector = new BackupFreshnessInspector(
            $this->catalog('2026-07-22 09:00:00'),
            $registry,
            $this->clock('2026-07-22 12:00:00'),
        );

        $this->assertCount(1, $inspector->inspect());
    }

    public function test_a_stream_of_wal_segments_cannot_mask_a_dead_backup_worker(): void
    {
        // THE REGRESSION. Continuous WAL archiving lands a wal_segment roughly every sixty seconds.
        // If freshness asks for "the newest artifact" it always finds one and always reports fresh
        // — even when the 6-hourly logical dump has not run for days. That silently disarms the one
        // alarm whose entire purpose is catching a backup worker that died quietly, which is the
        // 15-day production outage of 2026-07 arriving again by a different route.
        $walOnly = new class ($this->at('2026-07-27T03:59:00+00:00')) implements BackupCatalogReadModelInterface {
            public function __construct(private readonly DateTimeImmutable $walAt) {}

            public function byId(string $backupId): ?BackupArtifact { return null; }

            public function list(DatabaseEngine $engine, string $environment, ?BackupKind $kind = null): array { return []; }

            public function latest(DatabaseEngine $engine, string $environment): ?BackupArtifact
            {
                // Newest row overall: a WAL segment from one minute ago.
                return $this->wal();
            }

            public function latestOfKind(DatabaseEngine $engine, string $environment, array $kinds): ?BackupArtifact
            {
                // ...but there has not been a logical_full in days.
                return \in_array(BackupKind::WalSegment, $kinds, true) ? $this->wal() : null;
            }

            private function wal(): BackupArtifact
            {
                return new BackupArtifact(
                    BackupId::generate(DatabaseEngine::Postgres, BackupKind::WalSegment, $this->walAt),
                    DatabaseEngine::Postgres,
                    BackupKind::WalSegment,
                    'production',
                    $this->walAt,
                    16_777_216,
                    BackupChecksum::sha256(str_repeat('b', 64)),
                    'backups/production/postgres/wal/x',
                    CompressionCodec::None,
                    SourceRef::none(),
                );
            }
        };

        $inspector = new BackupFreshnessInspector(
            $walOnly,
            new BackupScheduleRegistry([
                new BackupSchedule('platform-backup', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'production', '0 */6 * * *', BackupScheduleType::Backup),
            ]),
            $this->clock('2026-07-27T04:00:00+00:00'),
        );

        $results = $inspector->inspect();

        self::assertNotSame([], $results);
        foreach ($results as $freshness) {
            self::assertNotSame(
                BackupFreshnessStatus::Fresh,
                $freshness->status,
                'WAL segments must not be mistaken for a successful database backup',
            );
        }
    }

    private function inspector(?string $lastBackupAt, string $now): BackupFreshnessInspector
    {
        return new BackupFreshnessInspector(
            $this->catalog($lastBackupAt),
            new BackupScheduleRegistry([
                new BackupSchedule('platform-backup', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'production', '0 */6 * * *', BackupScheduleType::Backup),
            ]),
            $this->clock($now),
        );
    }

    private function clock(string $at): ClockInterface
    {
        return new class($this->at($at)) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now) {}
            public function now(): DateTimeImmutable { return $this->now; }
        };
    }

    private function catalog(?string $latestAt): BackupCatalogReadModelInterface
    {
        $artifact = $latestAt === null ? null : new BackupArtifact(
            // The real id of the last backup production took before the worker went silent.
            BackupId::fromString('20260707T120000-postgres-logical_full-b295650fc0'),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            'production',
            $this->at($latestAt),
            1024,
            BackupChecksum::sha256(str_repeat('a', 64)),
            'backups/production/postgres/logical_full/x',
            CompressionCodec::None,
            SourceRef::none(),
        );

        return new class($artifact) implements BackupCatalogReadModelInterface {
            public function __construct(private readonly ?BackupArtifact $artifact) {}
            public function byId(string $backupId): ?BackupArtifact { return $this->artifact; }
            public function list(DatabaseEngine $engine, string $environment, ?BackupKind $kind = null): array
            {
                return $this->artifact === null ? [] : [$this->artifact];
            }
            public function latest(DatabaseEngine $engine, string $environment): ?BackupArtifact { return $this->artifact; }
            public function latestOfKind(DatabaseEngine $engine, string $environment, array $kinds): ?BackupArtifact
            {
                // Filters by kind, exactly as the real catalog does. A double that ignored $kinds
                // would let a kind-blindness bug pass unnoticed — which is the very bug this
                // interface exists to prevent.
                return $this->artifact !== null && \in_array($this->artifact->kind, $kinds, true)
                    ? $this->artifact
                    : null;
            }
        };
    }

    private function at(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }
}
