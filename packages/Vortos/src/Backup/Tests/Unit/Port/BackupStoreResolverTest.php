<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Port;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Port\BackupStoreResolver;
use Vortos\Backup\Tests\Support\ArtifactFactory;

/**
 * Where artifacts are written, and where they are looked for.
 *
 * The distinction these tests exist to protect: writing asks configuration, reading asks the
 * artifact. Get that backwards and repointing the WAL store sends a restore hunting for segments in
 * a bucket that never held them — a failure that surfaces mid-recovery and nowhere earlier.
 */
final class BackupStoreResolverTest extends TestCase
{
    public function test_without_a_wal_store_everything_resolves_to_primary(): void
    {
        $r = new BackupStoreResolver('object-store');

        self::assertSame('object-store', $r->forKind(BackupKind::WalSegment));
        self::assertSame('object-store', $r->forKind(BackupKind::PhysicalBase));
        self::assertSame('object-store', $r->forKind(BackupKind::LogicalFull));
        self::assertFalse($r->hasDedicatedWalStore());
    }

    public function test_only_wal_is_routed_to_the_wal_store(): void
    {
        $r = new BackupStoreResolver('object-store', 'object-store-wal');

        self::assertSame('object-store-wal', $r->forKind(BackupKind::WalSegment));
        self::assertSame('object-store', $r->forKind(BackupKind::PhysicalBase), 'A base backup wants the immutable bucket.');
        self::assertSame('object-store', $r->forKind(BackupKind::LogicalFull));
        self::assertSame('object-store', $r->forKind(BackupKind::MongoArchive));
        self::assertTrue($r->hasDedicatedWalStore());
    }

    /**
     * An artifact's own record wins over configuration.
     *
     * This is the case that matters after someone changes walStore(). Segments shipped under the old
     * setting are still in the old bucket, and the catalog is the only thing that knows it.
     */
    public function test_reading_follows_the_artifact_not_the_configuration(): void
    {
        $r = new BackupStoreResolver('object-store', 'object-store-wal-NEW');

        $shippedEarlier = ArtifactFactory::at(
            '2026-01-01 00:00:00',
            BackupKind::WalSegment,
            storeId: 'object-store-wal-OLD',
        );

        self::assertSame(
            'object-store-wal-OLD',
            $r->forArtifact($shippedEarlier),
            'Reading must not follow current config, or a restore looks in a bucket that never held it.',
        );
    }

    /**
     * A null storeId means the primary store, and that is a fact rather than a fallback: rows written
     * before the column existed are there because it was the only store there was.
     */
    public function test_an_unstamped_artifact_resolves_to_primary(): void
    {
        $r = new BackupStoreResolver('object-store', 'object-store-wal');

        $legacy = ArtifactFactory::at('2026-01-01 00:00:00', BackupKind::WalSegment);

        self::assertNull($legacy->storeId);
        self::assertSame('object-store', $r->forArtifact($legacy));
    }
}
