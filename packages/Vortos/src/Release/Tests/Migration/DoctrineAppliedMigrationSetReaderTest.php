<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Migration;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Release\Migration\DoctrineAppliedMigrationSetReader;

final class DoctrineAppliedMigrationSetReaderTest extends TestCase
{
    public function test_returns_empty_fingerprint_when_no_migrations_executed(): void
    {
        $reader = $this->buildReader([]);

        $fp = $reader->currentApplied();

        $this->assertTrue($fp->isEmpty());
        $this->assertSame([], $fp->migrationIds);
    }

    public function test_returns_fingerprint_with_executed_versions(): void
    {
        $reader = $this->buildReader([
            'App\\Migrations\\Version20260101000001',
            'App\\Migrations\\Version20260101000002',
        ]);

        $fp = $reader->currentApplied();

        $this->assertSame(2, $fp->count());
        $this->assertTrue($fp->contains('App\\Migrations\\Version20260101000001'));
        $this->assertTrue($fp->contains('App\\Migrations\\Version20260101000002'));
    }

    public function test_fingerprint_is_order_independent(): void
    {
        $readerA = $this->buildReader(['V2', 'V1', 'V3']);
        $readerB = $this->buildReader(['V1', 'V3', 'V2']);

        $this->assertTrue($readerA->currentApplied()->equals($readerB->currentApplied()));
    }

    public function test_fingerprint_is_deterministic_across_calls(): void
    {
        $reader = $this->buildReader(['V1', 'V2']);

        $fp1 = $reader->currentApplied();
        $fp2 = $reader->currentApplied();

        $this->assertSame($fp1->hash, $fp2->hash);
    }

    /** @param list<string> $versions */
    private function buildReader(array $versions): DoctrineAppliedMigrationSetReader
    {
        $executed = array_map(
            static fn (string $v) => new ExecutedMigration(new Version($v)),
            $versions,
        );

        $storage = $this->createMock(MetadataStorage::class);
        $storage->method('getExecutedMigrations')
            ->willReturn(new ExecutedMigrationsList($executed));

        $factory = $this->createMock(DependencyFactory::class);
        $factory->method('getMetadataStorage')
            ->willReturn($storage);

        $provider = $this->createMock(DependencyFactoryProviderInterface::class);
        $provider->method('create')
            ->willReturn($factory);

        return new DoctrineAppliedMigrationSetReader($provider);
    }
}
