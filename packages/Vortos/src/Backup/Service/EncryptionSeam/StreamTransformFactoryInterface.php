<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\EncryptionSeam;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * Builds the stream transform for one specific backup.
 *
 * WHY A FACTORY AND NOT A SERVICE. {@see StreamTransformInterface} was originally resolved as a single
 * shared service, which quietly made at-rest encryption impossible to switch on:
 * {@see EnvelopeStreamTransform} needs the engine, kind and codec of the backup it is encrypting —
 * they are bound into the envelope header and authenticated by the AEAD — but those values are only
 * known *per run*, and the codec in particular is decided by the dump target at dump time. A
 * container cannot construct that eagerly, so the envelope transform was never registered at all and
 * `StreamTransformInterface` stayed hard-aliased to the no-op. The seam was documented as
 * encryption-ready while being, in practice, unreachable.
 *
 * Moving the seam one level up fixes that: the runner asks for a transform once it knows what it is
 * about to write, and the composition root decides — from configuration — whether that is the
 * identity transform or a real envelope. It also keeps per-backup state (the envelope's
 * `lastMetadata()`) confined to a single backup rather than shared across concurrent runs.
 */
interface StreamTransformFactoryInterface
{
    public function forBackup(
        DatabaseEngine $engine,
        BackupKind $kind,
        CompressionCodec $codec,
    ): StreamTransformInterface;
}
