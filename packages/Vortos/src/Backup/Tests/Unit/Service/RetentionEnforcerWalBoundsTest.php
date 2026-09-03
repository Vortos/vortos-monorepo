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
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Tests\Support\ArtifactFactory;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;
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

    /**
     * Planning never touches the prunable WAL at all: it reports the count and leaves the artifacts
     * in the database. Hydration — bounded, one segment at a time — happens only when enforce()
     * streams the deletion, which the next test covers.
     */
    public function test_planning_reports_prunable_wal_as_a_count_without_hydrating_it(): void
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

        self::assertSame(12, $plan->walPruneCount, 'Prunable WAL must be reported as a count.');
        self::assertSame(100_000, $plan->keptWalCount);
        self::assertSame(0, $catalog->hydrated, 'Planning must not hydrate any WAL — prunable or retained.');
        self::assertFalse($plan->isNoop(), 'A plan with prunable WAL is not a noop, even with nothing else to delete.');
    }

    /**
     * Enforcing the prune streams the prunable segments — however many there are — one at a time,
     * so peak memory does not scale with the size of the backlog. This is the delete-path twin of
     * the planning bound above, and the path that actually took production down: retention ran, the
     * prune set was tens of thousands of segments after a lapse, and loading them at once exhausted
     * the worker. A hundred thousand here must delete a hundred thousand and stay bounded.
     */
    public function test_enforce_streams_the_prune_without_materialising_it(): void
    {
        $catalog = new CountingRetentionCatalog(
            restorePoints: [ArtifactFactory::at('2026-06-23 02:00:00', BackupKind::PhysicalBase)],
            walFrom: new DateTimeImmutable('2026-06-23 02:00:00'),
            walCount: 0,
            prunableWalCount: 100_000,
        );
        $store = new ObjectStoreBackupStore(new InMemoryObjectStore());

        $before = memory_get_usage();
        $plan = $this->enforcer($catalog)->enforce(
            $store,
            DatabaseEngine::Postgres,
            'prod',
            // Anchor on the retained base so the whole prunable history is older than it.
            new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30),
            apply: true,
        );
        $used = memory_get_usage() - $before;

        self::assertSame(100_000, $plan->walPruneCount, 'Every prunable segment must be deleted.');
        self::assertSame(100_000, $catalog->hydrated, 'Each deleted segment is hydrated exactly once, as it is streamed.');
        self::assertLessThan(
            16 * 1024 * 1024,
            $used,
            sprintf('Enforcing the prune allocated %d bytes; streaming must not scale with the backlog.', $used),
        );
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
        $policies = [
            'defaults' => new RetentionPolicy(),
            'gfs with max age' => new RetentionPolicy(daily: 7, weekly: 4, monthly: 6, yearly: 1, maxAgeDays: 30),
            'aggressive' => new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 2),
            'floor only' => new RetentionPolicy(daily: 0, weekly: 0, monthly: 0, yearly: 0, minKeepFloor: 3),
        ];

        foreach ($policies as $label => $policy) {
            // Fresh seed per policy: enforce() mutates the catalogue (it forgets what it deletes),
            // unlike the old plan()-only comparison, so the policies cannot share one catalogue.
            [$catalog, $seeded] = $this->seedProductionShapedCatalog();
            $object = new InMemoryObjectStore();
            foreach ($seeded as $artifact) {
                $object->objects[$artifact->storeKey] = 'x';
            }

            $this->enforcer($catalog)->enforce(
                new ObjectStoreBackupStore($object),
                DatabaseEngine::Postgres,
                'prod',
                $policy,
                apply: true,
            );

            // What enforce() actually removed from the store — restore points and streamed WAL
            // alike — against what the pre-fix algorithm would have deleted. Compared by store key
            // as a set; order is irrelevant and nothing downstream depends on it.
            $actual = $this->deletedStoreKeys($seeded, $object);
            $expected = $this->deleteKeys($this->originalPlan($seeded, $policy, new DateTimeImmutable(self::NOW)));

            self::assertSame(
                $expected,
                $actual,
                sprintf('Delete set diverged from the original algorithm under the "%s" policy.', $label),
            );
            self::assertNotSame([], $actual, sprintf('The "%s" policy deleted nothing, so it proves nothing.', $label));
        }
    }

    /**
     * A catalogue shaped like production: daily bases and logical dumps going back two years, with a
     * handful of WAL segments per day — one landing exactly on a base's instant, the boundary where
     * "strictly older" has to stay strict.
     *
     * @return array{0: InMemoryCatalogRepository, 1: list<BackupArtifact>}
     */
    private function seedProductionShapedCatalog(): array
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

        return [$catalog, $seeded];
    }

    /**
     * The store keys that enforce() deleted: everything seeded that no longer exists in the store.
     *
     * @param list<BackupArtifact> $seeded
     * @return list<string> sorted, so two runs compare as sets
     */
    private function deletedStoreKeys(array $seeded, InMemoryObjectStore $object): array
    {
        $deleted = [];
        foreach ($seeded as $artifact) {
            if (!\array_key_exists($artifact->storeKey, $object->objects)) {
                $deleted[] = $artifact->storeKey;
            }
        }
        sort($deleted);

        return $deleted;
    }

    /**
     * @return list<string> the plan's delete set as sorted store keys
     */
    private function deleteKeys(RetentionPlan $plan): array
    {
        $keys = array_map(static fn (BackupArtifact $a): string => $a->storeKey, $plan->delete);
        sort($keys);

        return $keys;
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

        self::assertNull($plan->walPruneAnchor, 'With no retained base there is no anchor, so nothing is prunable.');
        self::assertSame(0, $plan->walPruneCount, 'WAL must never be pruned without a retained base backup to replay it onto.');
        self::assertSame(5_000, $plan->keptWalCount);
        self::assertSame(0, $catalog->hydrated);
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

    public function iterateWalOlderThan(
        DatabaseEngine $engine,
        string $environment,
        DateTimeImmutable $before,
        int $batchSize = 1000,
    ): iterable {
        // A generator: each segment is built only as it is yielded, and $hydrated counts them one
        // by one. A consumer that holds the whole stream drives $hydrated to prunableWalCount while
        // never letting more than a single artifact — plus whatever it chooses to keep — live at
        // once. That is the property under test, so the double must not pre-build the list.
        for ($i = 0; $i < $this->prunableWalCount; $i++) {
            $this->hydrated++;
            yield ArtifactFactory::at(
                $this->walFrom->modify(sprintf('-%d minutes', $i + 1))->format('Y-m-d H:i:s'),
                BackupKind::WalSegment,
            );
        }
    }

    public function countWalOlderThan(DatabaseEngine $engine, string $environment, ?DateTimeImmutable $before): int
    {
        return $before === null ? 0 : $this->prunableWalCount;
    }

    public function countWalFrom(DatabaseEngine $engine, string $environment, ?DateTimeImmutable $from): int
    {
        return $from === null ? $this->walCount + $this->prunableWalCount : $this->walCount;
    }
}
