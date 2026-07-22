<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\EncryptionSeam;

use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Secrets\Key\KeyProviderInterface;

/**
 * Builds a fresh {@see EnvelopeStreamTransform} per backup: a new DEK each run, wrapped by the
 * configured off-host KEK, with the engine/kind/codec bound into the envelope header.
 *
 * A new instance per backup is deliberate, not incidental. The transform records the envelope's
 * `lastMetadata()` (provider, recipient, AEAD id) so the runner can catalog it; sharing one instance
 * across runs would let two concurrent backups overwrite each other's metadata and catalog an
 * artifact against the wrong recipient — which would surface only when a restore failed to decrypt.
 */
final class EnvelopeStreamTransformFactory implements StreamTransformFactoryInterface
{
    public function __construct(
        private readonly KeyProviderInterface $keyProvider,
        private readonly EnvelopeStreamCipher $cipher,
        private readonly string $providerName = 'age',
    ) {}

    public function forBackup(
        DatabaseEngine $engine,
        BackupKind $kind,
        CompressionCodec $codec,
    ): StreamTransformInterface {
        return new EnvelopeStreamTransform(
            $this->keyProvider,
            $this->cipher,
            $engine,
            $kind,
            $codec,
            $this->providerName,
        );
    }
}
