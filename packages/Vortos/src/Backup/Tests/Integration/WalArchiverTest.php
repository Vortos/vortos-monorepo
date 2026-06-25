<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Domain\Exception\BackupException;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Pitr\PostgresWalArchiver;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

final class WalArchiverTest extends TestCase
{
    private string $walFile;

    protected function setUp(): void
    {
        $this->walFile = sys_get_temp_dir() . '/wal-' . bin2hex(random_bytes(4));
        file_put_contents($this->walFile, random_bytes(16384));
    }

    protected function tearDown(): void
    {
        @unlink($this->walFile);
    }

    private function archiver(InMemoryObjectStore $object, InMemoryCatalogRepository $catalog): PostgresWalArchiver
    {
        $stores = new BackupStoreRegistry(new ServiceLocator(['object-store' => fn () => new ObjectStoreBackupStore($object)]));

        return new PostgresWalArchiver($stores, $catalog, new FixedClock(new DateTimeImmutable('now')), 'object-store', 'backups');
    }

    public function test_archives_a_segment_and_catalogs_it(): void
    {
        $object = new InMemoryObjectStore();
        $catalog = new InMemoryCatalogRepository();

        $artifact = $this->archiver($object, $catalog)->archive($this->walFile, 'prod');

        $this->assertCount(1, $object->objects);
        $this->assertArrayHasKey($artifact->id->value(), $catalog->rows);
        $this->assertStringContainsString('postgres/wal/' . basename($this->walFile), $artifact->storeKey);
    }

    public function test_re_archiving_identical_content_is_a_noop(): void
    {
        $object = new InMemoryObjectStore();
        $catalog = new InMemoryCatalogRepository();
        $archiver = $this->archiver($object, $catalog);

        $archiver->archive($this->walFile, 'prod');
        $countAfterFirst = count($object->objects);

        // Second archive of the same segment with identical content → success, no error.
        $archiver->archive($this->walFile, 'prod');
        $this->assertCount($countAfterFirst, $object->objects);
    }

    public function test_re_archiving_different_content_for_same_name_fails(): void
    {
        $object = new InMemoryObjectStore();
        $catalog = new InMemoryCatalogRepository();
        $archiver = $this->archiver($object, $catalog);

        $archiver->archive($this->walFile, 'prod');

        // Overwrite the local segment with different bytes but the same name.
        file_put_contents($this->walFile, random_bytes(16384));

        $this->expectException(BackupException::class);
        $archiver->archive($this->walFile, 'prod');
    }

    public function test_missing_segment_fails_closed(): void
    {
        $this->expectException(BackupException::class);
        $this->archiver(new InMemoryObjectStore(), new InMemoryCatalogRepository())->archive('/no/such/wal', 'prod');
    }
}
