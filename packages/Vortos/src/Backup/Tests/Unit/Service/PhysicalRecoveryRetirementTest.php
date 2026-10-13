<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Tests\Support\ArtifactFactory;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

/**
 * Retiring the framework's point-in-time recovery after the pgBackRest cutover: every physical base and
 * every WAL segment goes, from the store and the catalog; the logical dumps stay.
 */
final class PhysicalRecoveryRetirementTest extends TestCase
{
    private InMemoryCatalogRepository $catalog;
    private InMemoryObjectStore $objects;
    private RetentionEnforcer $enforcer;
    private BackupArtifact $dump;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryCatalogRepository();
        $this->objects = new InMemoryObjectStore();
        $this->enforcer = new RetentionEnforcer($this->catalog, $this->catalog, new CollectingEventSink(), new FixedClock(new DateTimeImmutable('2026-10-13 12:00:00')));

        $this->dump = $this->add('2026-10-13 00:00:00', BackupKind::LogicalFull);
        $this->add('2026-10-11 02:00:00', BackupKind::PhysicalBase);
        $this->add('2026-10-12 02:00:00', BackupKind::PhysicalBase);
        $this->add('2026-10-12 02:01:00', BackupKind::WalSegment);
        $this->add('2026-10-13 11:59:00', BackupKind::WalSegment);
        $this->add('2026-10-13 11:59:30', BackupKind::WalSegment);
    }

    public function test_a_dry_run_counts_and_deletes_nothing(): void
    {
        $result = $this->enforcer->retirePhysicalRecovery(new ObjectStoreBackupStore($this->objects), DatabaseEngine::Postgres, 'prod', apply: false);

        self::assertFalse($result->applied);
        self::assertSame(2, $result->bases);
        self::assertSame(3, $result->segments);
        self::assertSame(2048, $result->baseBytes);
        self::assertCount(6, $this->catalog->rows);
        self::assertCount(6, $this->objects->objects);
    }

    public function test_applying_removes_bases_and_wal_and_keeps_the_dumps(): void
    {
        $result = $this->enforcer->retirePhysicalRecovery(new ObjectStoreBackupStore($this->objects), DatabaseEngine::Postgres, 'prod', apply: true);

        self::assertSame(2, $result->bases);
        self::assertSame(3, $result->segments);
        self::assertSame(0, $result->locked);
        self::assertSame([$this->dump->id->value()], array_keys($this->catalog->rows));
        self::assertSame([$this->dump->storeKey], array_keys($this->objects->objects));
    }

    /** An Object Lock refusal leaves the object AND its row, so a later run can finish once the lock lapses. */
    public function test_a_locked_object_is_left_with_its_row_and_counted(): void
    {
        $locked = $this->add('2026-10-13 11:58:00', BackupKind::WalSegment);
        $this->objects->immutableKeys = [$locked->storeKey];

        $result = $this->enforcer->retirePhysicalRecovery(new ObjectStoreBackupStore($this->objects), DatabaseEngine::Postgres, 'prod', apply: true);

        self::assertSame(1, $result->locked);
        self::assertSame(3, $result->segments);
        self::assertArrayHasKey($locked->id->value(), $this->catalog->rows);
        self::assertArrayHasKey($locked->storeKey, $this->objects->objects);
    }

    private function add(string $at, BackupKind $kind): BackupArtifact
    {
        $artifact = ArtifactFactory::at($at, $kind);
        $this->catalog->record($artifact);
        $this->objects->objects[$artifact->storeKey] = 'x';

        return $artifact;
    }
}
