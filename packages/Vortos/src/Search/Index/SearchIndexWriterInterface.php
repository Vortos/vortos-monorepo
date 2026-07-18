<?php

declare(strict_types=1);

namespace Vortos\Search\Index;

use Vortos\Search\Document\SearchDocument;

/**
 * Writes documents into the index. Idempotent by (tenant, type, entityId): {@see upsert()}
 * inserts or replaces, {@see delete()} removes (no-op if absent). Both the live projection
 * handler and the backfill command write through this one seam, so an external-engine app
 * swaps indexing and querying together.
 */
interface SearchIndexWriterInterface
{
    public function upsert(SearchDocument $document): void;

    public function delete(string $type, string $entityId, string $tenantId): void;

    /** Drop every row for a type within a tenant — used by `--fresh` backfills. */
    public function purgeType(string $type, string $tenantId): int;
}
