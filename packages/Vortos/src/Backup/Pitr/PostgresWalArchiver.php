<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\BackupAlreadyExistsException;
use Vortos\Backup\Catalog\BackupCatalogRepositoryInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\BackupException;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Crypto\EnvelopeHeader;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\EncryptionMetadata;
use Vortos\Secrets\Key\KeyProviderInterface;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransform;
use Vortos\Backup\Service\EncryptionSeam\StreamTransformFactoryInterface;

/**
 * Ships a single Postgres WAL segment to the backup store — the hook a host's
 * `archive_command = 'vortos backup:wal-archive %p'` invokes for continuous archiving.
 *
 * **Idempotent**, honouring the archive_command contract: re-archiving a segment whose
 * stored bytes already match is a success no-op; re-archiving a *different* payload for
 * an existing segment name **fails** (Postgres must never have a segment silently
 * overwritten with different content).
 *
 * **Encrypted at rest, on the same seam as base backups.** WAL segments used to ship in
 * plaintext while the bases they replay onto were envelope-encrypted, which made the
 * encryption largely decorative: a WAL stream carries every row change, so an attacker with
 * the object store could reconstruct the database from segments alone without ever touching a
 * base. `EnvelopeHeader` already reserved a kind byte for WalSegment, so the format always
 * anticipated this.
 *
 * Idempotency is compared on PLAINTEXT, never on stored bytes. The envelope uses a fresh
 * nonce per run, so the same segment encrypted twice produces different ciphertext — a
 * ciphertext comparison would report every retry as a conflicting payload and fail the
 * archive_command, which Postgres treats as an un-archivable segment. The existing object is
 * therefore decrypted before comparison. That only happens on the rare re-archive path.
 */
final class PostgresWalArchiver
{
    public function __construct(
        private readonly BackupStoreRegistry $stores,
        private readonly BackupCatalogRepositoryInterface $catalog,
        private readonly ClockInterface $clock,
        private readonly string $storeKey,
        private readonly string $keyPrefix,
        private readonly ?StreamTransformFactoryInterface $transforms = null,
        private readonly ?EnvelopeStreamCipher $cipher = null,
        private readonly ?KeyProviderInterface $keyProvider = null,
    ) {}

    public function archive(string $absolutePath, string $environment): BackupArtifact
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new BackupException("WAL segment not found or unreadable: {$absolutePath}");
        }

        $segmentName = basename($absolutePath);
        $store = $this->stores->store($this->storeKey);
        $objectKey = sprintf('%s/%s/postgres/wal/%s', trim($this->keyPrefix, '/'), $environment, $segmentName);

        $local = $this->checksumOfFile($absolutePath);

        if ($store->exists($objectKey)) {
            $this->assertIdenticalOrFail($store, $objectKey, $local, $segmentName);

            // Already archived with identical content → success no-op.
            return $this->artifact($segmentName, $environment, $objectKey, $local, (int) filesize($absolutePath));
        }

        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new BackupException("Cannot open WAL segment: {$absolutePath}");
        }

        // Built per segment, exactly as BackupRunner does: the envelope binds engine/kind/codec
        // into its authenticated header, so the transform cannot be a shared singleton.
        $transform = $this->transforms?->forBackup(
            DatabaseEngine::Postgres,
            BackupKind::WalSegment,
            CompressionCodec::None,
        );

        try {
            $source = $transform !== null ? $transform->transform($handle) : $handle;

            $stream = new BackupStream($source, DatabaseEngine::Postgres, BackupKind::WalSegment, CompressionCodec::None, SourceRef::none());
            $stored = $store->store($stream, $objectKey);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $encryption = $transform instanceof EnvelopeStreamTransform
            ? $transform->lastMetadata()
            : null;

        // Only meaningful unencrypted: with an envelope, the stored bytes are ciphertext and will
        // never equal the plaintext digest. Ciphertext integrity is carried by the AEAD tag, which
        // fails closed on read, so nothing is lost by skipping the comparison here.
        if ($encryption === null && !$stored->checksum->equals($local)) {
            $store->delete($objectKey);
            throw new BackupException("WAL segment {$segmentName} corrupted in transit (checksum mismatch).");
        }

        $artifact = $this->artifact($segmentName, $environment, $objectKey, $stored->checksum, $stored->sizeBytes, $encryption);

        try {
            $this->catalog->record($artifact);
        } catch (BackupAlreadyExistsException) {
            // Concurrent archive of the same segment — harmless given content matched.
        }

        return $artifact;
    }

    private function assertIdenticalOrFail(BackupStoreInterface $store, string $objectKey, BackupChecksum $local, string $segmentName): void
    {
        $stream = $store->open($objectKey);
        if (!is_resource($stream)) {
            throw new BackupException("Cannot read existing WAL segment '{$segmentName}' for idempotency check.");
        }
        try {
            // Decrypt before digesting when the stored object is an envelope. Comparing ciphertext
            // would fail every retry — the nonce is fresh per run, so identical plaintext produces
            // different bytes — and a failed archive_command makes Postgres retry the same segment
            // forever, eventually filling pg_wal and stopping writes. The comparison is defined on
            // segment CONTENT, so content is what gets compared.
            $existing = $this->isEnvelope($stream)
                ? $this->checksumOfDecrypted($stream, $local, $segmentName)
                : BackupChecksum::ofStream($stream, $local->algorithm);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (!$existing->equals($local)) {
            throw new BackupException(sprintf(
                "WAL segment '%s' already archived with different content — refusing to overwrite (archive_command must be idempotent).",
                $segmentName,
            ));
        }
    }

    /**
     * Reads the envelope magic without consuming the stream for the non-envelope case.
     *
     * A segment archived before encryption was switched on is still a plain WAL file, and must stay
     * readable — switching encryption on cannot retroactively break the idempotency check for
     * everything already in the store.
     */
    private function isEnvelope(mixed $stream): bool
    {
        $magic = fread($stream, \strlen(EnvelopeHeader::MAGIC));
        rewind($stream);

        return $magic === EnvelopeHeader::MAGIC;
    }

    private function checksumOfDecrypted(mixed $stream, BackupChecksum $local, string $segmentName): BackupChecksum
    {
        if ($this->cipher === null || $this->keyProvider === null) {
            throw new BackupException(sprintf(
                "WAL segment '%s' is encrypted but no key provider is configured — cannot verify idempotency.",
                $segmentName,
            ));
        }

        $context = hash_init($local->algorithm);

        foreach ($this->cipher->decryptStreamLazy($stream, fn ($wrapped) => $this->keyProvider->unwrap($wrapped)) as $chunk) {
            hash_update($context, $chunk);
        }

        return BackupChecksum::of($local->algorithm, hash_final($context));
    }

    private function artifact(string $segmentName, string $environment, string $objectKey, BackupChecksum $checksum, int $size, ?EncryptionMetadata $encryption = null): BackupArtifact
    {
        return new BackupArtifact(
            BackupId::generate(DatabaseEngine::Postgres, BackupKind::WalSegment, $this->clock->now()),
            DatabaseEngine::Postgres,
            BackupKind::WalSegment,
            $environment,
            $this->clock->now(),
            $size,
            $checksum,
            $objectKey,
            CompressionCodec::None,
            SourceRef::walLsn($segmentName),
            null,
            null,
            $encryption,
        );
    }

    private function checksumOfFile(string $path): BackupChecksum
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new BackupException("Cannot open WAL segment: {$path}");
        }
        try {
            return BackupChecksum::ofStream($handle);
        } finally {
            fclose($handle);
        }
    }
}
