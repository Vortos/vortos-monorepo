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

/**
 * Ships a single Postgres WAL segment to the backup store — the hook a host's
 * `archive_command = 'vortos backup:wal-archive %p'` invokes for continuous archiving.
 *
 * **Idempotent**, honouring the archive_command contract: re-archiving a segment whose
 * stored bytes already match is a success no-op; re-archiving a *different* payload for
 * an existing segment name **fails** (Postgres must never have a segment silently
 * overwritten with different content).
 */
final class PostgresWalArchiver
{
    public function __construct(
        private readonly BackupStoreRegistry $stores,
        private readonly BackupCatalogRepositoryInterface $catalog,
        private readonly ClockInterface $clock,
        private readonly string $storeKey,
        private readonly string $keyPrefix,
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

        try {
            $stream = new BackupStream($handle, DatabaseEngine::Postgres, BackupKind::WalSegment, CompressionCodec::None, SourceRef::none());
            $stored = $store->store($stream, $objectKey);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (!$stored->checksum->equals($local)) {
            $store->delete($objectKey);
            throw new BackupException("WAL segment {$segmentName} corrupted in transit (checksum mismatch).");
        }

        $artifact = $this->artifact($segmentName, $environment, $objectKey, $stored->checksum, $stored->sizeBytes);

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
            $existing = BackupChecksum::ofStream($stream, $local->algorithm);
        } finally {
            fclose($stream);
        }

        if (!$existing->equals($local)) {
            throw new BackupException(sprintf(
                "WAL segment '%s' already archived with different content — refusing to overwrite (archive_command must be idempotent).",
                $segmentName,
            ));
        }
    }

    private function artifact(string $segmentName, string $environment, string $objectKey, BackupChecksum $checksum, int $size): BackupArtifact
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
