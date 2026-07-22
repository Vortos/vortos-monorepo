<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\EncryptionSeam;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * The default: no transform. Backups are stored exactly as the dump target produced them.
 *
 * Note this is not the same as "uncompressed" — `pg_dump --format=custom --compress=6` is already
 * zlib-compressed on the wire; the catalog's `codec` column records the *additional* stream codec
 * applied here, which is none.
 */
final class IdentityStreamTransformFactory implements StreamTransformFactoryInterface
{
    public function __construct(
        private readonly IdentityStreamTransform $transform = new IdentityStreamTransform(),
    ) {}

    public function forBackup(
        DatabaseEngine $engine,
        BackupKind $kind,
        CompressionCodec $codec,
    ): StreamTransformInterface {
        return $this->transform;
    }
}
