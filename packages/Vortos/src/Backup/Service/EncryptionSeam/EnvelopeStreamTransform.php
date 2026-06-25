<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\EncryptionSeam;

use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\EncryptionMetadata;
use Vortos\Secrets\Key\DataKey;
use Vortos\Secrets\Key\KeyProviderInterface;

/**
 * Encrypts the backup stream using envelope encryption: a fresh per-backup DEK
 * (secretstream XChaCha20-Poly1305) wrapped by the configured off-host KEK via
 * the Secrets key provider. Plugs into the existing {@see StreamTransformInterface}
 * seam — zero changes to {@see \Vortos\Backup\Service\BackupRunner}.
 */
final class EnvelopeStreamTransform implements StreamTransformInterface
{
    private ?EncryptionMetadata $lastMetadata = null;

    public function __construct(
        private readonly KeyProviderInterface $keyProvider,
        private readonly EnvelopeStreamCipher $cipher,
        private readonly DatabaseEngine $engine,
        private readonly BackupKind $kind,
        private readonly CompressionCodec $codec,
        private readonly string $providerName = 'age',
    ) {}

    public function transform(mixed $source): mixed
    {
        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $dataKey = DataKey::fromRaw($dek);
        $wrapped = $this->keyProvider->wrap($dataKey);

        [$encrypted, $header] = $this->cipher->encrypt(
            $source,
            $dek,
            $this->providerName,
            $wrapped->recipientId,
            $wrapped->ciphertext,
            $this->codec,
            $this->engine,
            $this->kind,
        );

        sodium_memzero($dek);
        $dataKey->wipe();

        $this->lastMetadata = new EncryptionMetadata(
            $this->providerName,
            $wrapped->recipientId,
            $header->aeadId,
        );

        return $encrypted;
    }

    public function name(): string
    {
        return 'age-envelope';
    }

    public function lastMetadata(): ?EncryptionMetadata
    {
        return $this->lastMetadata;
    }
}
