<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Catalog\RetentionCatalogInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\RetentionPlan;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Tests\Support\ArtifactFactory;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;

/**
 * Retention planning must stay bounded no matter how much WAL has accumulated, and must select
 * exactly the same artifacts for deletion as the original all-in-memory implementation did.
 *
 * The first property is why this file exists: planning used to hydrate every catalogue row, which
 * against a WAL segment every few minutes and a fixed memory_limit is a scheduled outage rather
 * than a slow leak. It took the whole backup lifecycle with it, retention running first.
 *
 * The second property is what makes the first one safe to ship. This is deletion of backups: a fix
 * that bounds memory but shifts the cut-off by one segment trades a loud failure for a silent one.
 */
final class RetentionEnforcerWalBoundsTest extends TestCase
{
    private const NOW = '2026-06-23 12:00:00';

    /**
     * The regression test for the outage. A catalogue holding 100k retained WAL segments must plan
     * without materialising them.
     *
     * Deliberately asserts on artifacts hydrated rather than only on bytes: hydration count is the
     * property that actually broke, and it is deterministic across hosts and PHP versions in a way
     * that a memory figure is not. The byte assertion backs it up — 100k artifacts is a couple of
     * hundred megabytes, so a regression cannot slip through under a generous cap.
     */
    public function test_planning_does_not_hydrate_retained_wal(): void
    {
        $base = new DateTimeImmutable('2026-06-23 02:00:00');
        $catalog = new CountingRetentionCatalog(
            restorePoints: [ArtifactFactory::at('2026-06-23 02:00:00', BackupKind::PhysicalBase)],
            // Every segment postdates the only retained base, so none of them is prunable —
            // the shape production is actually in, and the one that exhausted the worker.
            walFrom: $base,
            walCount: 100_000,
        );

        $before = memory_get_usage();
        $plan = $this->enforcer($catalog)->plan(DatabaseEngine::Postgres, 'prod', new RetentionPolicy());
        $used = memory_get_usage() - $before;

        self::assertSame(100_000, $plan->keptWalCount, 'Retained WAL must still be reported, as a count.');
        self::assertSame(
            0,
            $catalog->hydrated,
            'Retained WAL was hydrated into objects. That set has no upper bound; it may only be counted.',
        );
        self::assertLessThan(
            8 * 1024 * 1024,
            $used,
            sprintf('Planning allocated %d bytes against a catalogue it should never have read.', $used),
        );
    }

    /** The prunable side is bounded too: only the segments actually being deleted are loaded. */
    public function test_planning_hydrates_only_the_wal_it_deletes(): void
    {
        $catalog = new CountingRetentionCatalog(
            restorePoints: [
                ArtifactFactory::at('2026-06-23 02:00:00', BackupKind::PhysicalBase),
                ArtifactFactory::at('2020-01-01 02:00:00', BackupKind::PhysicalBase),
            ],
            walFrom: new DateTimeImmutable('2026-06-23 02:00:00'),
            walCount: 100_000,
            prunableWalCount: 12,
        );

        $plan = $this->enforcer($catalog)->plan(
            DatabaseEngine::Postgres,
            'prod',
            new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30),
        );

        self::assertSame(12, $catalog->hydrated, 'Only the segments being pruned may be loaded.');
        self::assertSame(100_000, $plan->keptWalCount);
    }

    /**
     * Equivalence against the original algorithm, over a catalogue shaped like production: daily
     * bases and logical dumps going back two years, with WAL interleaved throughout.
     *
     * The oracle below is the pre-fix implementation, kept verbatim. If the SQL boundary and the
     * in-PHP comparison ever disagree — a timezone applied on one side only, `<` drifting to `<=` —
     * this fails, and it fails on identity of the deleted set, not on a count.
     */
    public function test_delete_set_matches_the_original_in_memory_algorithm(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $seeded = [];

        $cursor = new DateTimeImmutable('2024-06-23 02:00:00');
        $end = new DateTimeImmutable(self::NOW);
        while ($cursor < $end) {
            foreach ([BackupKind::PhysicalBase, BackupKind::LogicalFull] as $kind) {
                $artifact = ArtifactFactory::at($cursor->format('Y-m-d H:i:s'), $kind);
                $catalog->record($artifact);
                $seeded[] = $artifact;
            }
            // A handful of segments per day, including one landing exactly on a base backup's
            // instant — the boundary where "strictly older" has to be strict.
            foreach (['+0 hours', '+3 hours', '+11 hours', '+19 hours'] as $offset) {
                $artifact = ArtifactFactory::at(
                    $cursor->modify($offset)->format('Y-m-d H:i:s'),
                    BackupKind::WalSegment,
                );
                $catalog->record($artifact);
                $seeded[] = $artifact;
            }
            $cursor = $cursor->modify('+1 day');
        }

        $policies = [
            'defaults' => new RetentionPolicy(),
            'gfs with max age' => new RetentionPolicy(daily: 7, weekly: 4, monthly: 6, yearly: 1, maxAgeDays: 30),
            'aggressive' => new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 2),
            'floor only' => new RetentionPolicy(daily: 0, weekly: 0, monthly: 0, yearly: 0, minKeepFloor: 3),
        ];

        foreach ($policies as $label => $policy) {
            $actual = $this->enforcer($catalog)->plan(DatabaseEngine::Postgres, 'prod', $policy);
            $expected = $this->originalPlan($seeded, $policy, new DateTimeImmutable(self::NOW));

            // Compared as sets: the catalogue returns newest-first and the oracle walks its seed
            // list chronologically, and nothing downstream depends on the order — enforce() deletes
            // every entry. What must not differ is which artifacts are in it.
            self::assertSame(
                $this->deletedIds($expected),
                $this->deletedIds($actual),
                sprintf('Delete set diverged from the original algorithm under the "%s" policy.', $label),
            );
            self::assertNotSame([], $this->deletedIds($actual), sprintf('The "%s" policy deleted nothing, so it proves nothing.', $label));
        }
    }

    /**
     * With no retained base backup there is no anchor to replay WAL onto, and the original
     * implementation pruned nothing. That is the safety valve in this whole path — pruning WAL on
     * the strength of an absent base is how a PITR window silently stops being restorable — so it
     * gets a test of its own rather than living inside the equivalence sweep.
     */
    public function test_no_retained_base_prunes_no_wal(): void
    {
        $catalog = new CountingRetentionCatalog(
            restorePoints: [ArtifactFactory::at('2020-01-01 02:00:00', BackupKind::LogicalFull)],
            // Every segment predates the cut-off, so all 5,000 would be prunable if a retained base
            // existed to justify it. None does.
            walFrom: new DateTimeImmutable('2020-01-01 02:00:00'),
            walCount: 0,
            prunableWalCount: 5_000,
        );

        $plan = $this->enforcer($catalog)->plan(
            DatabaseEngine::Postgres,
            'prod',
            new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 1),
        );

        self::assertSame([], array_values(array_filter(
            $plan->delete,
            static fn (BackupArtifact $a): bool => $a->isWalSegment(),
        )), 'WAL must never be pruned without a retained base backup to replay it onto.');
        self::assertSame(5_000, $plan->keptWalCount);
        self::assertSame(0, $catalog->hydrated);
    }

    /** @return list<string> deleted artifact ids, sorted so two plans compare as sets */
    private function deletedIds(RetentionPlan $plan): array
    {
        $ids = array_map(static fn (BackupArtifact $a): string => $a->id->value(), $plan->delete);
        sort($ids);

        return $ids;
    }

    private function enforcer(RetentionCatalogInterface $catalog): RetentionEnforcer
    {
        $repository = $catalog instanceof InMemoryCatalogRepository ? $catalog : new InMemoryCatalogRepository();

        return new RetentionEnforcer(
            $catalog,
            $repository,
            new CollectingEventSink(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );
    }

    /**
     * The pre-fix implementation, preserved as a test oracle: hydrate everything, split in PHP,
     * keep WAL at or after the oldest retained base.
     *
     * @param list<BackupArtifact> $all
     */
    private function originalPlan(array $all, RetentionPolicy $policy, DateTimeImmutable $now): RetentionPlan
    {
        $restorePoints = array_values(array_filter($all, static fn (BackupArtifact $a): bool => $a->isRestorePoint()));
        $wal = array_values(array_filter($all, static fn (BackupArtifact $a): bool => $a->isWalSegment()));

        $rpPlan = $policy->plan($restorePoints, $now);

        $oldestKeptBase = null;
        foreach ($rpPlan->keep as $kept) {
            if ($kept->kind === BackupKind::PhysicalBase) {
                if ($oldestKeptBase === null || $kept->createdAt < $oldestKeptBase) {
                    $oldestKeptBase = $kept->createdAt;
                }
            }
        }

        $keepWal = [];
        $deleteWal = [];
        foreach ($wal as $segment) {
            if ($oldestKeptBase === null || $segment->createdAt >= $oldestKeptBase) {
                $keepWal[] = $segment;
            } else {
                $deleteWal[] = $segment;
            }
        }

        return new RetentionPlan(
            [...$rpPlan->keep, ...$keepWal],
            [...$rpPlan->delete, ...$deleteWal],
            $rpPlan->refused,
        );
    }
}

/**
 * A catalogue that can produce an arbitrarily large WAL history but only builds the artifacts it is
 * actually asked for, and records how many it built.
 *
 * The point is that asking for too much is expensive here, exactly as it is in production. A test
 * double that could not produce a hundred thousand segments would prove nothing about the bound.
 *
 * @internal
 */
final class CountingRetentionCatalog implements RetentionCatalogInterface
{
    public int $hydrated = 0;

    /**
     * @param list<BackupArtifact> $restorePoints
     * @param DateTimeImmutable    $walFrom          the instant the WAL history is split around
     * @param int                  $walCount         segments at or after $walFrom (the retained side)
     * @param int                  $prunableWalCount segments before $walFrom (the prunable side)
     */
    public function __construct(
        private readonly array $restorePoints,
        private readonly DateTimeImmutable $walFrom,
        private readonly int $walCount,
        private readonly int $prunableWalCount = 0,
    ) {}

    public function listRestorePoints(DatabaseEngine $engine, string $environment): array
    {
        $sorted = $this->restorePoints;
        usort($sorted, static fn (BackupArtifact $a, BackupArtifact $b): int => $b->createdAt <=> $a->createdAt);

        return $sorted;
    }

    public function listWalOlderThan(DatabaseEngine $engine, string $environment, DateTimeImmutable $before): array
    {
        $out = [];
        for ($i = 0; $i < $this->prunableWalCount; $i++) {
            $out[] = ArtifactFactory::at(
                $this->walFrom->modify(sprintf('-%d minutes', $i + 1))->format('Y-m-d H:i:s'),
                BackupKind::WalSegment,
            );
            $this->hydrated++;
        }

        return $out;
    }

    public function countWalFrom(DatabaseEngine $engine, string $environment, ?DateTimeImmutable $from): int
    {
        return $from === null ? $this->walCount + $this->prunableWalCount : $this->walCount;
    }
}
