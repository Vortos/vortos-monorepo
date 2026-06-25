<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use DateTimeImmutable;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;

/** @internal builds artifacts for retention/catalog tests */
final class ArtifactFactory
{
    public static function at(
        string $iso,
        BackupKind $kind = BackupKind::LogicalFull,
        DatabaseEngine $engine = DatabaseEngine::Postgres,
        string $env = 'prod',
    ): BackupArtifact {
        $created = new DateTimeImmutable($iso);

        return new BackupArtifact(
            BackupId::generate($engine, $kind, $created),
            $engine,
            $kind,
            $env,
            $created,
            1024,
            BackupChecksum::ofString('data-' . $iso),
            sprintf('backups/%s/%s/%s/%s', $env, $engine->value, $kind->value, str_replace([':', '-', ' '], '', $iso)),
            CompressionCodec::None,
            SourceRef::none(),
        );
    }
}
