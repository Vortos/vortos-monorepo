<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\UnknownEngineException;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Tests\Support\ArtifactFactory;

final class DomainValueObjectsTest extends TestCase
{
    public function test_engine_from_string_rejects_unknown(): void
    {
        $this->assertSame(DatabaseEngine::Postgres, DatabaseEngine::fromString('postgres'));
        $this->expectException(UnknownEngineException::class);
        DatabaseEngine::fromString('mysql');
    }

    public function test_backup_id_is_sortable_and_validated(): void
    {
        $early = BackupId::generate(DatabaseEngine::Postgres, BackupKind::LogicalFull, new DateTimeImmutable('2026-01-01 00:00:00'));
        $late = BackupId::generate(DatabaseEngine::Postgres, BackupKind::LogicalFull, new DateTimeImmutable('2026-12-01 00:00:00'));

        $this->assertLessThan(0, strcmp($early->value(), $late->value()));
        $this->assertTrue(BackupId::fromString($early->value())->equals($early));
    }

    public function test_backup_id_rejects_malformed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BackupId::fromString('not-a-valid-id');
    }

    public function test_checksum_streaming_equals_oneshot(): void
    {
        $data = random_bytes(100_000);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $data);
        rewind($stream);

        $this->assertTrue(BackupChecksum::ofStream($stream)->equals(BackupChecksum::ofString($data)));
        fclose($stream);
    }

    public function test_checksum_equals_is_algorithm_sensitive(): void
    {
        $a = BackupChecksum::ofString('x');
        $b = BackupChecksum::of('sha512', hash('sha512', 'x'));
        $this->assertFalse($a->equals($b));
    }

    public function test_source_ref_round_trip(): void
    {
        foreach ([SourceRef::none(), SourceRef::walLsn('0/16B6B50'), SourceRef::oplogTimestamp('123,4')] as $ref) {
            $this->assertEquals($ref, SourceRef::fromArray($ref->toArray()));
        }
    }

    public function test_artifact_round_trip(): void
    {
        $artifact = ArtifactFactory::at('2026-06-23 02:00:00');
        $rebuilt = \Vortos\Backup\Domain\BackupArtifact::fromArray($artifact->toArray());

        $this->assertSame($artifact->id->value(), $rebuilt->id->value());
        $this->assertSame($artifact->storeKey, $rebuilt->storeKey);
        $this->assertTrue($artifact->checksum->equals($rebuilt->checksum));
        $this->assertEquals($artifact->createdAt->getTimestamp(), $rebuilt->createdAt->getTimestamp());
    }

    public function test_artifact_round_trip_with_db_style_string_source_ref(): void
    {
        $artifact = ArtifactFactory::at('2026-06-23 02:00:00');
        $row = $artifact->toArray();
        $row['source_ref'] = json_encode($row['source_ref']); // as DBAL would return it
        $rebuilt = \Vortos\Backup\Domain\BackupArtifact::fromArray($row);

        $this->assertSame($artifact->id->value(), $rebuilt->id->value());
    }

    public function test_kind_classification(): void
    {
        $this->assertTrue(BackupKind::WalSegment->isWalSegment());
        $this->assertFalse(BackupKind::WalSegment->isRestorePoint());
        $this->assertTrue(BackupKind::PhysicalBase->isRestorePoint());
    }
}
