<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * The store operations the sweeper needs: enumerate chains with archivable records,
 * read a chain forward from the frontier, and purge a chain up to a sequence.
 * Implemented by the DBAL store.
 */
interface AuditRetentionSourceInterface
{
    /**
     * @return list<string> chain_keys that have at least one record older than $cutoff
     */
    public function chainsWithRecordsBefore(\DateTimeImmutable $cutoff): array;

    /**
     * @return list<StoredAuditEvent> records with sequence > $afterSequence, ascending, capped
     */
    public function readChain(string $chainKey, int $afterSequence, int $limit): array;

    /** Delete records in a chain with sequence <= $sequence. Returns rows removed. */
    public function deleteChainUpTo(string $chainKey, int $sequence): int;
}
