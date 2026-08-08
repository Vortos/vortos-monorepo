<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\ObjectLockPolicy;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Tests\Support\ArtifactFactory;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;

final class RetentionEnforcerWormTest extends TestCase
{
    public function test_locked_artifact_excluded_from_delete_plan(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $events = new CollectingEventSink();
        $now = new DateTimeImmutable('2026-06-24');
        $clock = new FixedClock($now);

        // Create an artifact from 5 days ago (within 30-day lock)
        $recent = ArtifactFactory::at('2026-06-20 02:00:00');
        // Create an artifact from 60 days ago (outside 30-day lock)
        $old = ArtifactFactory::at('2026-04-25 02:00:00');

        $catalog->record($recent);
        $catalog->record($old);

        $lockPolicy = new ObjectLockPolicy('compliance', 30);
        $enforcer = new RetentionEnforcer($catalog, $catalog, $events, $clock, $lockPolicy);

        // Use a policy that would delete the old one
        $policy = new RetentionPolicy(hourly: 0, daily: 1, weekly: 0, monthly: 0, yearly: 0, minKeepFloor: 1);
        $plan = $enforcer->plan(DatabaseEngine::Postgres, 'prod', $policy);

        // The recent artifact should be kept, the old one can be deleted (outside lock window)
        $deletedKeys = array_map(fn ($a) => $a->storeKey, $plan->delete);
        $keptKeys = array_map(fn ($a) => $a->storeKey, $plan->keep);

        $this->assertContains($recent->storeKey, $keptKeys);
        // Old artifact is outside the 30-day lock window, so eligible for deletion
    }

    public function test_legal_hold_prevents_all_deletion(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $events = new CollectingEventSink();
        $clock = new FixedClock(new DateTimeImmutable('2026-06-24'));

        // Old artifact from a year ago, but legal hold is on
        $old = ArtifactFactory::at('2025-06-20 02:00:00');
        $newer = ArtifactFactory::at('2026-06-20 02:00:00');
        $catalog->record($old);
        $catalog->record($newer);

        $lockPolicy = new ObjectLockPolicy('compliance', 30, legalHold: true);
        $enforcer = new RetentionEnforcer($catalog, $catalog, $events, $clock, $lockPolicy);

        $policy = new RetentionPolicy(hourly: 0, daily: 1, weekly: 0, monthly: 0, yearly: 0, minKeepFloor: 1);
        $plan = $enforcer->plan(DatabaseEngine::Postgres, 'prod', $policy);

        // With legal hold, nothing should be in the delete set
        $this->assertSame([], $plan->delete, 'Legal hold must prevent all deletions.');
    }

    public function test_object_lock_policy_rejects_invalid_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectLockPolicy('invalid', 30);
    }

    public function test_object_lock_policy_rejects_zero_days(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectLockPolicy('compliance', 0);
    }

    public function test_within_retention_window(): void
    {
        $policy = new ObjectLockPolicy('compliance', 30);
        $created = new DateTimeImmutable('2026-06-01');
        $within = new DateTimeImmutable('2026-06-15');
        $outside = new DateTimeImmutable('2026-07-15');

        $this->assertTrue($policy->isWithinRetention($created, $within));
        $this->assertFalse($policy->isWithinRetention($created, $outside));
    }

    /**
     * A WORM bucket with NO declared ObjectLockPolicy must not break retention.
     *
     * This is the production case. `sqoura-prod` has an R2 bucket lock rule and the app never
     * declared VORTOS_BACKUP_OBJECT_LOCK_DAYS, so the very first locked object threw: retention
     * failed its occurrence, burned five retries, raised an alert and pruned nothing — every day,
     * indefinitely — while nothing was actually wrong. Whether the app mirrored the bucket's
     * immutability in configuration has no bearing on what the store just told us.
     */
    public function test_store_immutability_is_honoured_without_a_declared_lock_policy(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $events  = new CollectingEventSink();
        $clock   = new FixedClock(new DateTimeImmutable('2026-06-24'));

        $keep    = ArtifactFactory::at('2026-06-23 02:00:00');
        $locked  = ArtifactFactory::at('2026-01-01 02:00:00');
        $catalog->record($keep);
        $catalog->record($locked);

        $object = new InMemoryObjectStore();
        $object->objects[$locked->storeKey] = 'x';
        $object->immutableKeys = [$locked->storeKey];
        $store = new ObjectStoreBackupStore($object);

        // NOTE: no ObjectLockPolicy passed — exactly the production wiring.
        $enforcer = new RetentionEnforcer($catalog, $catalog, $events, $clock);
        $policy   = new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30);

        $plan = $enforcer->enforce($store, DatabaseEngine::Postgres, 'prod', $policy, apply: true);

        $reasons = array_map(static fn (array $r): string => $r['reason'], $plan->refused);
        self::assertContains('retained (locked by store)', $reasons, 'The refusal must be reported, not swallowed.');
        self::assertSame([], $plan->delete, 'Nothing was actually deleted.');
        self::assertArrayHasKey($locked->id->value(), $catalog->rows, 'The catalog row must survive with the object.');
    }

    /** The emitted event must count what was deleted, not what was merely planned. */
    public function test_retention_event_counts_actual_deletions(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $events  = new CollectingEventSink();
        $clock   = new FixedClock(new DateTimeImmutable('2026-06-24'));

        $keep     = ArtifactFactory::at('2026-06-23 02:00:00');
        $locked   = ArtifactFactory::at('2026-01-01 02:00:00');
        $deletable = ArtifactFactory::at('2026-02-01 02:00:00');
        foreach ([$keep, $locked, $deletable] as $a) { $catalog->record($a); }

        $object = new InMemoryObjectStore();
        foreach ([$locked, $deletable] as $a) { $object->objects[$a->storeKey] = 'x'; }
        $object->immutableKeys = [$locked->storeKey];
        $store = new ObjectStoreBackupStore($object);

        $enforcer = new RetentionEnforcer($catalog, $catalog, $events, $clock);
        $policy   = new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30);

        $plan = $enforcer->enforce($store, DatabaseEngine::Postgres, 'prod', $policy, apply: true);

        self::assertCount(1, $plan->delete, 'Only the deletable artifact counts as deleted.');
        self::assertSame($deletable->id->value(), $plan->delete[0]->id->value());
        self::assertArrayNotHasKey($deletable->id->value(), $catalog->rows);
        self::assertArrayHasKey($locked->id->value(), $catalog->rows);
    }

    /**
     * A database error in the same try block must still surface.
     *
     * The old matcher was `str_contains($msg, 'lock')`, which "deadlock detected" satisfies — so a
     * transient Postgres failure during the catalog write would have been filed as an immutable
     * object and the artifact quietly left behind.
     */
    public function test_a_deadlock_is_not_mistaken_for_immutability(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $events  = new CollectingEventSink();
        $clock   = new FixedClock(new DateTimeImmutable('2026-06-24'));

        $keep = ArtifactFactory::at('2026-06-23 02:00:00');
        $old  = ArtifactFactory::at('2026-01-01 02:00:00');
        $catalog->record($keep);
        $catalog->record($old);

        $object = new InMemoryObjectStore();
        $object->deleteError = 'SQLSTATE[40P01]: deadlock detected';
        $store = new ObjectStoreBackupStore($object);

        $enforcer = new RetentionEnforcer($catalog, $catalog, $events, $clock);
        $policy   = new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/deadlock/');
        $enforcer->enforce($store, DatabaseEngine::Postgres, 'prod', $policy, apply: true);
    }
}
