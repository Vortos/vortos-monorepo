<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage;

/**
 * Read side of the store, used by the chain verifier and (later) export. Kept separate
 * from the write path so a write-only backend (e.g. a log shipper) can exist without
 * implementing reads.
 */
interface AuditReaderInterface
{
    /**
     * The tail of a chain, or null if the chain is empty.
     *
     * @return array{sequence: int, content_hash: string}|null
     */
    public function chainTail(string $chainKey): ?array;

    /**
     * Chained records for one chain, sequence ascending, sequence > $afterSequence,
     * capped at $limit — the walk primitive for verification and export.
     *
     * @return list<StoredAuditEvent>
     */
    public function readChain(string $chainKey, int $afterSequence, int $limit): array;
}
