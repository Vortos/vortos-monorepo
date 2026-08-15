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
 *
 * **Compressed before encryption**, when a codec is configured. A segment is a fixed 16 MiB
 * whatever it holds, and `archive_timeout` ships it on a clock rather than when it fills, so most
 * of what reaches the store is zero padding — see {@see WalCompression} for the measured cost.
 * Compression must precede encryption: ciphertext is indistinguishable from random and does not
 * compress, so the reverse order would spend the CPU and save nothing. The envelope binds the
 * codec into its authenticated header, which is what lets the read path reverse the pipeline
 * without consulting the catalog (the catalog lives in the database being restored).
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
        // Defaults to off, so a host that has not opted in behaves exactly as before.
        private readonly WalCompressionSettings $compression = new WalCompressionSettings(),
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
            $this->compression->codec,
        );

        try {
            // BEFORE the transform, so the envelope encrypts compressed bytes rather than
            // compressing encrypted ones. A read filter, so the transform still sees one plain
            // readable resource and needs no knowledge of the codec beyond its header.
            if ($this->compression->enabled()) {
                WalCompression::attachDeflate($handle, $this->compression->level);
            }

            $source = $transform !== null ? $transform->transform($handle) : $handle;

            $stream = new BackupStream($source, DatabaseEngine::Postgres, BackupKind::WalSegment, $this->compression->codec, SourceRef::none());
            $stored = $store->store($stream, $objectKey);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $encryption = $transform instanceof EnvelopeStreamTransform
            ? $transform->lastMetadata()
            : null;

        // Only meaningful when the stored bytes ARE the segment bytes. With an envelope they are
        // ciphertext, and with gzip they are a compressed member — neither will ever equal the
        // plaintext digest. Integrity does not go unclaimed in those cases: the AEAD tag covers the
        // envelope and the gzip CRC32/ISIZE trailer covers the member, and both fail closed on read
        // (see WalCompression::maybeInflate, which finalises with ZLIB_FINISH for exactly this).
        if ($encryption === null && !$this->compression->enabled() && !$stored->checksum->equals($local)) {
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
            // Reverse the FULL pipeline before digesting, envelope then codec, because the
            // comparison is defined on segment CONTENT.
            //
            // Comparing stored bytes would fail every retry: the envelope nonce is fresh per run,
            // so identical plaintext encrypts to different bytes, and gzip output varies with the
            // level a past run happened to use. A failed archive_command makes Postgres retry the
            // same segment forever, eventually filling pg_wal and stopping writes.
            //
            // Both layers are detected per object rather than read from configuration. The store
            // holds segments from before encryption was switched on and from before compression
            // was, and turning either on must not retroactively break the idempotency check for
            // everything already archived under the old settings.
            $plain = $this->isEnvelope($stream)
                ? $this->decryptedChunks($stream, $segmentName)
                : WalCompression::chunks($stream);

            $existing = $this->checksumOfChunks(WalCompression::maybeInflate($plain), $local->algorithm);
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

    /**
     * @return \Generator<int, string, void, void>
     *
     * @throws BackupException
     */
    private function decryptedChunks(mixed $stream, string $segmentName): \Generator
    {
        $cipher      = $this->cipher;
        $keyProvider = $this->keyProvider;

        if ($cipher === null || $keyProvider === null) {
            throw new BackupException(sprintf(
                "WAL segment '%s' is encrypted but no key provider is configured — cannot verify idempotency.",
                $segmentName,
            ));
        }

        yield from $cipher->decryptStreamLazy($stream, static fn ($wrapped) => $keyProvider->unwrap($wrapped));
    }

    /**
     * @param iterable<int, string> $chunks
     */
    private function checksumOfChunks(iterable $chunks, string $algorithm): BackupChecksum
    {
        $context = hash_init($algorithm);

        foreach ($chunks as $chunk) {
            hash_update($context, $chunk);
        }

        return BackupChecksum::of($algorithm, hash_final($context));
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
            $this->compression->codec,
            SourceRef::walLsn($segmentName),
            null,
            null,
            $encryption,
            null,
            // The store this archiver was configured with. WAL may be routed to a bucket of its own,
            // and a restore has to find these segments without trusting that config still points
            // where it pointed when they were shipped.
            $this->storeKey,
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
