<?php

declare(strict_types=1);

namespace Vortos\Audit\Admin;

use Vortos\Audit\Export\AuditExport;
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Integrity\ChainVerificationResult;
use Vortos\Audit\Query\AuditFacets;
use Vortos\Audit\Query\AuditPage;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Retention\AuditCheckpoint;
use Vortos\Audit\Retention\AuditCheckpointStoreInterface;
use Vortos\Audit\Storage\AuditReaderInterface;

/**
 * The app-facing facade the audit admin endpoints (platform console / org settings) call.
 * One cohesive surface over read, export, and integrity verification, so controllers stay
 * thin and every consumer verifies chains the same way.
 */
final class AuditAdminService
{
    public function __construct(
        private readonly AuditQueryInterface             $query,
        private readonly AuditReaderInterface             $reader,
        private readonly AuditChainVerifier               $verifier,
        private readonly \Vortos\Audit\Export\AuditExporter $exporter,
        private readonly string                          $hmacKey = '',
        private readonly ?AuditCheckpointStoreInterface  $checkpoints = null,
        private readonly int                             $verifyBatchSize = 1000,
    ) {}

    public function page(AuditQuery $query): AuditPage
    {
        return $this->query->page($query);
    }

    /** Facet counts (by action / sensitivity / outcome) for the console's filter rail. */
    public function facets(AuditQuery $query): AuditFacets
    {
        return $this->query->facets($query);
    }

    public function export(AuditQuery $query): AuditExport
    {
        return $this->exporter->export($query);
    }

    public function retentionFrontier(string $chainKey): ?AuditCheckpoint
    {
        return $this->checkpoints?->find($chainKey);
    }

    /**
     * Verify a whole chain, streaming batch-by-batch so a multi-million-record chain never
     * loads into memory. Verification resumes from the archival checkpoint (records purged
     * to cold storage are covered by the checkpoint's tail hash, not re-read).
     */
    public function verifyChain(string $chainKey): ChainVerificationResult
    {
        $checkpoint = $this->checkpoints?->find($chainKey);
        $afterSeq   = $checkpoint?->lastSequence ?? 0;
        $prevHash   = $checkpoint?->lastContentHash ?? AuditHashChain::GENESIS_HASH;
        $expectSeq  = $afterSeq + 1;
        $verified   = 0;

        while (true) {
            $batch = $this->reader->readChain($chainKey, $afterSeq, $this->verifyBatchSize);
            if ($batch === []) {
                break;
            }

            $result = $this->verifier->verify($batch, $this->hmacKey, $expectSeq, $prevHash);
            if (!$result->valid) {
                return ChainVerificationResult::broken(
                    $verified + $result->verifiedCount,
                    (int) $result->brokenSequence,
                    (string) $result->reason,
                );
            }

            $verified += $result->verifiedCount;
            $last      = $batch[array_key_last($batch)];
            $afterSeq  = $last->sequence;
            $prevHash  = $last->contentHash;
            $expectSeq = $last->sequence + 1;

            if (count($batch) < $this->verifyBatchSize) {
                break;
            }
        }

        return ChainVerificationResult::ok($verified);
    }
}
