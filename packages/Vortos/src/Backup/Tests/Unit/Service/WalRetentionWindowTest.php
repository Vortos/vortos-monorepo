<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Catalog\BackupCatalogRepositoryInterface;
use Vortos\Backup\Catalog\RetentionCatalogInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Tests\Support\FixedClock;

/**
 * The explicit PITR window — `walRetentionDays` — and the invariant that makes it safe.
 *
 * WHY THIS EXISTS. Without it, WAL is retained back to the oldest retained restore point, so the
 * PITR window is a side effect of restore-point retention rather than a decision. On one production
 * system that meant 30 days of WAL to support restore points nobody would ever roll forward from:
 * a fault found three weeks later is served by the nearest periodic dump, not by replaying to an
 * exact instant three weeks ago. WAL volume tracks write ACTIVITY while restore points track
 * database SIZE, and tying them together made the expensive one unreachable.
 *
 * THE INVARIANT UNDER TEST. Pruning anchors on the newest retained base AT OR BEFORE the cutoff,
 * never on the cutoff instant itself. Anchoring on the instant would delete the segments between
 * the last base and the cutoff, leaving that base restorable to its own consistency point but
 * unable to roll forward — a database that looks recoverable and silently is not, discovered only
 * during a restore. The window is therefore a floor, never a ceiling.
 */
final class WalRetentionWindowTest extends TestCase
{
    private const NOW = '2026-08-16 12:00:00';

    private function artifact(BackupKind $kind, DateTimeImmutable $at): BackupArtifact
    {
        return new BackupArtifact(
            BackupId::generate(DatabaseEngine::Postgres, $kind, $at),
            DatabaseEngine::Postgres,
            $kind,
            'production',
            $at,
            1024,
            BackupChecksum::of('sha256', str_repeat('a', 64)),
            'k/' . $kind->value . '/' . $at->format('YmdHis'),
            CompressionCodec::None,
            SourceRef::none(),
        );
    }

    /**
     * @param list<BackupArtifact> $restorePoints
     *
     * @return array{?DateTimeImmutable, RetentionEnforcer}
     */
    private function enforcer(array $restorePoints, ?DateTimeImmutable &$askedFor): RetentionEnforcer
    {
        $catalog = new class ($restorePoints, $askedFor) implements RetentionCatalogInterface {
            /** @param list<BackupArtifact> $restorePoints */
            public function __construct(
                private readonly array $restorePoints,
                private ?DateTimeImmutable &$askedFor,
            ) {}

            public function listRestorePoints(DatabaseEngine $engine, string $environment): array
            {
                return $this->restorePoints;
            }

            public function iterateWalOlderThan(
                DatabaseEngine $engine,
                string $environment,
                DateTimeImmutable $before,
                int $batchSize = 1000,
            ): iterable {
                return [];
            }

            /**
             * Records the anchor the enforcer chose — the value under test. Planning now sizes the
             * prunable slice with a count rather than listing it, so this is where the anchor is
             * observed; it is only reached when an anchor exists, exactly as the delete path is.
             */
            public function countWalOlderThan(DatabaseEngine $engine, string $environment, ?DateTimeImmutable $before): int
            {
                $this->askedFor = $before;

                return 0;
            }

            public function countWalFrom(DatabaseEngine $engine, string $environment, ?DateTimeImmutable $from): int
            {
                return 0;
            }
        };

        $repository = $this->createStub(BackupCatalogRepositoryInterface::class);
        $events     = new class implements BackupEventSinkInterface {
            public function emit(BackupEvent $event): void {}
        };

        return new RetentionEnforcer($catalog, $repository, $events, new FixedClock(new DateTimeImmutable(self::NOW)));
    }

    private function policy(?int $walRetentionDays): RetentionPolicy
    {
        // maxAgeDays(30) keeps every restore point inside the window, isolating WAL behaviour.
        return new RetentionPolicy(
            hourly: 0, daily: 7, weekly: 4, monthly: 0, yearly: 0,
            maxAgeDays: 30, minKeepFloor: 1, walRetentionDays: $walRetentionDays,
        );
    }

    /** Weekly bases going back 28 days, plus a dump each day (dumps must never anchor WAL). */
    private function weeklyBases(): array
    {
        $now   = new DateTimeImmutable(self::NOW);
        $items = [];
        foreach ([0, 7, 14, 21, 28] as $daysAgo) {
            $items[] = $this->artifact(BackupKind::PhysicalBase, $now->modify("-{$daysAgo} days"));
        }
        foreach (range(0, 29) as $daysAgo) {
            $items[] = $this->artifact(BackupKind::LogicalFull, $now->modify("-{$daysAgo} days"));
        }

        return $items;
    }

    public function test_without_a_window_wal_is_anchored_on_the_oldest_retained_base(): void
    {
        $asked = null;
        $this->enforcer($this->weeklyBases(), $asked)
            ->plan(DatabaseEngine::Postgres, 'production', $this->policy(null));

        // Legacy behaviour: 28 days back, the oldest retained base.
        $this->assertSame('2026-07-19 12:00:00', $asked?->format('Y-m-d H:i:s'));
    }

    /**
     * THE POINT OF THE FEATURE. A 7-day window with weekly bases anchors on the 7-day-old base,
     * discarding the three older weeks of WAL while every retained base stays replayable.
     */
    public function test_a_window_anchors_on_the_newest_base_at_or_before_the_cutoff(): void
    {
        $asked = null;
        $this->enforcer($this->weeklyBases(), $asked)
            ->plan(DatabaseEngine::Postgres, 'production', $this->policy(7));

        $this->assertSame('2026-08-09 12:00:00', $asked?->format('Y-m-d H:i:s'));
    }

    /**
     * THE SAFETY INVARIANT. The anchor must never land after the cutoff — that would delete WAL the
     * window promises. With a 10-day window and weekly bases the correct anchor is the 14-day-old
     * base, NOT the 7-day-old one, even though the latter is closer to the cutoff.
     */
    public function test_the_anchor_never_falls_inside_the_window(): void
    {
        $asked = null;
        $this->enforcer($this->weeklyBases(), $asked)
            ->plan(DatabaseEngine::Postgres, 'production', $this->policy(10));

        $cutoff = (new DateTimeImmutable(self::NOW))->modify('-10 days');

        $this->assertNotNull($asked);
        $this->assertLessThanOrEqual($cutoff, $asked, 'anchor fell inside the PITR window — WAL the window promises would be deleted');
        $this->assertSame('2026-08-02 12:00:00', $asked->format('Y-m-d H:i:s'));
    }

    /** The window is a floor: a window shorter than the base cadence still keeps a full cadence of WAL. */
    public function test_a_window_shorter_than_the_base_cadence_keeps_more_wal_not_less(): void
    {
        $asked = null;
        $this->enforcer($this->weeklyBases(), $asked)
            ->plan(DatabaseEngine::Postgres, 'production', $this->policy(1));

        // Newest base at or before yesterday is the 7-day-old one, so ~7 days of WAL survive.
        $this->assertSame('2026-08-09 12:00:00', $asked?->format('Y-m-d H:i:s'));
    }

    /** A window longer than restore-point retention must prune LESS, never more. */
    public function test_a_window_longer_than_retention_falls_back_to_the_oldest_base(): void
    {
        $asked = null;
        $this->enforcer($this->weeklyBases(), $asked)
            ->plan(DatabaseEngine::Postgres, 'production', $this->policy(365));

        $this->assertSame('2026-07-19 12:00:00', $asked?->format('Y-m-d H:i:s'));
    }

    /**
     * Logical dumps are not replay anchors — WAL cannot be rolled forward onto a pg_dump. A store
     * holding only dumps must prune no WAL at all.
     */
    public function test_logical_dumps_never_anchor_wal_pruning(): void
    {
        $now   = new DateTimeImmutable(self::NOW);
        $dumps = [];
        foreach (range(0, 29) as $daysAgo) {
            $dumps[] = $this->artifact(BackupKind::LogicalFull, $now->modify("-{$daysAgo} days"));
        }

        $asked = null;
        $this->enforcer($dumps, $asked)->plan(DatabaseEngine::Postgres, 'production', $this->policy(7));

        $this->assertNull($asked, 'WAL was pruned with no physical base to replay onto');
    }

    public function test_no_retained_base_prunes_nothing(): void
    {
        $asked = null;
        $this->enforcer([], $asked)->plan(DatabaseEngine::Postgres, 'production', $this->policy(7));

        $this->assertNull($asked);
    }

    public function test_the_policy_rejects_a_nonsensical_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetentionPolicy(walRetentionDays: 0);
    }
}
