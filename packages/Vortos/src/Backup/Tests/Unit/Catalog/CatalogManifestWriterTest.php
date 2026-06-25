<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Catalog\CatalogManifestWriter;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Tests\Support\ArtifactFactory;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

final class CatalogManifestWriterTest extends TestCase
{
    public function test_manifest_written_alongside_artifacts(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $catalog->record(ArtifactFactory::at('2026-06-23 02:00:00'));
        $catalog->record(ArtifactFactory::at('2026-06-24 02:00:00'));

        $objectStore = new InMemoryObjectStore();
        $store = new ObjectStoreBackupStore($objectStore);

        $writer = new CatalogManifestWriter($catalog);
        $writer->write($store, 'backups', DatabaseEngine::Postgres, 'prod');

        $key = 'backups/prod/postgres/catalog-manifest.json';
        $this->assertArrayHasKey($key, $objectStore->objects);

        $manifest = json_decode($objectStore->objects[$key], true);
        $this->assertCount(2, $manifest);
        $this->assertSame('prod', $manifest[0]['environment']);
    }

    public function test_manifest_roundtrips_to_artifact_view(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $original = ArtifactFactory::at('2026-06-24 02:00:00');
        $catalog->record($original);

        $objectStore = new InMemoryObjectStore();
        $store = new ObjectStoreBackupStore($objectStore);

        $writer = new CatalogManifestWriter($catalog);
        $writer->write($store, 'backups', DatabaseEngine::Postgres, 'prod');

        $key = 'backups/prod/postgres/catalog-manifest.json';
        $manifest = json_decode($objectStore->objects[$key], true);
        $rebuilt = \Vortos\Backup\Domain\BackupArtifact::fromArray($manifest[0]);

        $this->assertSame($original->id->value(), $rebuilt->id->value());
        $this->assertSame($original->storeKey, $rebuilt->storeKey);
        $this->assertTrue($original->checksum->equals($rebuilt->checksum));
    }
}
