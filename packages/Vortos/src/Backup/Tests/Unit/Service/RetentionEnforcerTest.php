<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Tests\Support\ArtifactFactory;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

final class RetentionEnforcerTest extends TestCase
{
    private InMemoryCatalogRepository $catalog;
    private CollectingEventSink $events;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryCatalogRepository();
        $this->events = new CollectingEventSink();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-06-23 12:00:00'));
    }

    private function enforcer(): RetentionEnforcer
    {
        return new RetentionEnforcer($this->catalog, $this->catalog, $this->events, $this->clock);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        foreach (['2026-06-23 02:00:00', '2026-06-10 02:00:00', '2020-01-01 02:00:00'] as $iso) {
            $this->catalog->record(ArtifactFactory::at($iso));
        }
        $object = new InMemoryObjectStore();
        $store = new ObjectStoreBackupStore($object);

        $policy = new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30);
        $plan = $this->enforcer()->enforce($store, DatabaseEngine::Postgres, 'prod', $policy, apply: false);

        $this->assertNotEmpty($plan->delete);
        $this->assertCount(3, $this->catalog->rows, 'Dry-run must not remove catalog rows.');
        $this->assertNotContains(BackupEvent::TYPE_RETENTION_APPLIED, $this->events->types());
    }

    public function test_apply_deletes_planned_rows_and_emits_event(): void
    {
        $keep = ArtifactFactory::at('2026-06-23 02:00:00');
        $drop = ArtifactFactory::at('2020-01-01 02:00:00');
        $this->catalog->record($keep);
        $this->catalog->record($drop);

        $object = new InMemoryObjectStore();
        $object->objects[$keep->storeKey] = 'x';
        $object->objects[$drop->storeKey] = 'y';
        $store = new ObjectStoreBackupStore($object);

        $policy = new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30);
        $this->enforcer()->enforce($store, DatabaseEngine::Postgres, 'prod', $policy, apply: true);

        $this->assertArrayHasKey($keep->id->value(), $this->catalog->rows);
        $this->assertArrayNotHasKey($drop->id->value(), $this->catalog->rows);
        $this->assertArrayNotHasKey($drop->storeKey, $object->objects, 'Stored object should be deleted.');
        $this->assertArrayHasKey($keep->storeKey, $object->objects);
        $this->assertContains(BackupEvent::TYPE_RETENTION_APPLIED, $this->events->types());
    }

    public function test_wal_kept_when_at_or_after_oldest_retained_base(): void
    {
        // A base backup retained on 06-20, WAL before and after it.
        $base = ArtifactFactory::at('2026-06-20 00:00:00', BackupKind::PhysicalBase);
        $walAfter = ArtifactFactory::at('2026-06-22 00:00:00', BackupKind::WalSegment);
        $walBefore = ArtifactFactory::at('2026-06-10 00:00:00', BackupKind::WalSegment);
        foreach ([$base, $walAfter, $walBefore] as $a) {
            $this->catalog->record($a);
        }

        $policy = new RetentionPolicy(daily: 30, weekly: 0, monthly: 0, yearly: 0);
        $plan = $this->enforcer()->plan(DatabaseEngine::Postgres, 'prod', $policy);

        $deleteIds = array_map(static fn ($a) => $a->id->value(), $plan->delete);
        $this->assertContains($walBefore->id->value(), $deleteIds, 'WAL older than the oldest retained base is prunable.');
        $this->assertNotContains($walAfter->id->value(), $deleteIds, 'WAL at/after the base must be kept.');
        $this->assertNotContains($base->id->value(), $deleteIds);
    }

    public function test_wal_never_deleted_when_no_base_retained(): void
    {
        $wal = ArtifactFactory::at('2020-01-01 00:00:00', BackupKind::WalSegment);
        $this->catalog->record($wal);

        $policy = new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 1);
        $plan = $this->enforcer()->plan(DatabaseEngine::Postgres, 'prod', $policy);

        $deleteIds = array_map(static fn ($a) => $a->id->value(), $plan->delete);
        $this->assertNotContains($wal->id->value(), $deleteIds, 'Without a retained base, WAL is kept conservatively.');
    }
}
