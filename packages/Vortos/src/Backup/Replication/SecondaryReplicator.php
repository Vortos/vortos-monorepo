<?php

declare(strict_types=1);

namespace Vortos\Backup\Replication;

use Throwable;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Port\BackupStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Post-store replicator: streams copy #1 to a secondary store (different provider/region).
 * Re-verifies checksum on the copy. Failure emits Critical but does NOT void copy #1.
 */
final class SecondaryReplicator
{
    public function __construct(
        private readonly ?BackupStoreInterface $secondaryStore,
        private readonly BackupEventSinkInterface $events,
        private readonly ClockInterface $clock,
    ) {}

    public function replicate(
        BackupArtifact $artifact,
        BackupStoreInterface $primaryStore,
    ): ReplicationResult {
        if ($this->secondaryStore === null) {
            return ReplicationResult::skipped($artifact->storeKey);
        }

        try {
            $source = $primaryStore->open($artifact->storeKey);
            if (!is_resource($source)) {
                throw new \RuntimeException('Cannot open primary artifact for replication.');
            }

            $secondaryKey = 'secondary/' . $artifact->storeKey;

            $tempStream = fopen('php://temp', 'r+b');
            if ($tempStream === false) {
                throw new \RuntimeException('Cannot create temp stream for replication.');
            }

            $ctx = hash_init($artifact->checksum->algorithm);
            while (!feof($source)) {
                $chunk = fread($source, 1 << 20);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
                fwrite($tempStream, $chunk);
            }
            fclose($source);
            $recomputedChecksum = BackupChecksum::of($artifact->checksum->algorithm, hash_final($ctx));

            if (!$artifact->checksum->equals($recomputedChecksum)) {
                throw new \RuntimeException(sprintf(
                    'Primary checksum mismatch during replication: expected %s, got %s.',
                    $artifact->checksum->hex,
                    $recomputedChecksum->hex,
                ));
            }

            rewind($tempStream);

            $backupStream = new \Vortos\Backup\Port\BackupStream(
                $tempStream,
                $artifact->engine,
                $artifact->kind,
                $artifact->codec,
                $artifact->sourceRef,
            );
            $stored = $this->secondaryStore->store($backupStream, $secondaryKey);

            if (!$artifact->checksum->equals($stored->checksum)) {
                throw new \RuntimeException(sprintf(
                    'Secondary checksum mismatch: expected %s, got %s.',
                    $artifact->checksum->hex,
                    $stored->checksum->hex,
                ));
            }

            return ReplicationResult::success($artifact->storeKey, $secondaryKey);
        } catch (Throwable $e) {
            $this->events->emit(BackupEvent::replicationFailed(
                $artifact->engine,
                $artifact->environment,
                $e->getMessage(),
                $this->clock->now(),
            ));

            return ReplicationResult::failed($artifact->storeKey, $e->getMessage());
        }
    }
}
