<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Audit\Observability\AuditMetrics;
use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * Archive-then-purge retention. For each chain it takes the contiguous run of records
 * past that chain's cutoff (starting at the archival frontier), writes them to cold
 * storage as NDJSON, advances the checkpoint to the last archived record, and only then
 * deletes them from the hot table. Because purge is a contiguous prefix and the
 * checkpoint records the tail hash, the remaining hot chain still verifies from the
 * checkpoint — no holes, no broken links.
 *
 * Ordering is strict: write → checkpoint → delete. A crash between steps leaves data
 * safely archived (at worst re-archived next run); it can never delete un-archived data.
 */
final class AuditRetentionSweeper
{
    public function __construct(
        private readonly AuditRetentionSourceInterface    $source,
        private readonly AuditCheckpointStoreInterface     $checkpoints,
        private readonly AuditArchiveWriterInterface        $archiveWriter,
        private readonly AuditRetentionPolicy               $policy,
        private readonly StoredAuditEventSerializer         $serializer,
        private readonly ClockInterface                     $clock,
        private readonly int                                $batchSize = 1000,
        private readonly LoggerInterface                    $logger = new NullLogger(),
        private readonly ?AuditMetrics                       $metrics = null,
    ) {}

    public function sweep(bool $dryRun = false): RetentionResult
    {
        $now    = $this->clock->now();
        $result = new RetentionResult();

        // A chain is a candidate if any record predates the widest possible cutoff; the
        // per-chain policy then applies the precise window.
        foreach ($this->source->chainsWithRecordsBefore($now) as $chainKey) {
            $cutoff = $this->policy->cutoffFor($chainKey, $now);
            if ($cutoff === null) {
                continue; // retention disabled for this chain
            }

            $frontier = $this->checkpoints->find($chainKey)?->lastSequence ?? 0;
            $batch    = $this->source->readChain($chainKey, $frontier, $this->batchSize);

            /** @var list<StoredAuditEvent> $expired */
            $expired = [];
            foreach ($batch as $record) {
                if ($record->event->occurredAt >= $cutoff) {
                    break; // first not-yet-expired record ends the contiguous run
                }
                $expired[] = $record;
            }

            if ($expired === []) {
                continue;
            }

            $last  = $expired[array_key_last($expired)];
            $count = count($expired);
            $result->record($chainKey, $count);

            if ($dryRun) {
                continue;
            }

            $ndjson    = $this->serializer->toNdjson(...$expired);
            $objectKey = $this->archiveWriter->write($chainKey, $expired[0]->sequence, $last->sequence, $ndjson);

            $this->checkpoints->save(new AuditCheckpoint(
                chainKey:        $chainKey,
                lastSequence:    $last->sequence,
                lastContentHash: $last->contentHash,
                archivedAt:      $now,
                objectKey:       $objectKey,
                recordCount:     $count,
            ));

            $deleted = $this->source->deleteChainUpTo($chainKey, $last->sequence);

            $this->metrics?->archived($count);
            $this->metrics?->purged($deleted);

            $this->logger->info('Audit retention swept a chain.', [
                'chain_key'  => $chainKey,
                'archived'   => $count,
                'deleted'    => $deleted,
                'object_key' => $objectKey,
                'up_to_seq'  => $last->sequence,
            ]);
        }

        return $result;
    }
}
