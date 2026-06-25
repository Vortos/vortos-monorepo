<?php
declare(strict_types=1);

namespace Vortos\Auth\Audit\Contract;

use Vortos\Auth\Audit\AuditEntry;

/**
 * Optional read-side companion to AuditStoreInterface.
 *
 * AuditStoreInterface is write-only by design (audit backends range from
 * Postgres to an append-only log shipper, and most never need to read
 * entries back). Implement this interface on top of your store ONLY if you
 * want `vortos:auth:verify-audit-chain` to be able to walk and verify the
 * hash chain. Not required for audit recording or chain writing.
 */
interface AuditChainReaderInterface
{
    /**
     * @return list<AuditEntry> chained entries ordered by sequence ascending,
     *     with sequence > $afterSequence, capped at $limit.
     */
    public function findChainedEntries(int $afterSequence, int $limit): array;
}
