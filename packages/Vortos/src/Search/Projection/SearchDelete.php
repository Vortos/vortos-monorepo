<?php

declare(strict_types=1);

namespace Vortos\Search\Projection;

/**
 * Projection outcome: remove this document from the index. Emitted by a
 * {@see SearchableProjection} when a domain event deletes/archives a searchable aggregate, so a
 * hit never outlives the thing it points at. Idempotent — a no-op if the row is already gone.
 */
final class SearchDelete
{
    public function __construct(
        public readonly string $type,
        public readonly string $entityId,
        public readonly string $tenantId,
    ) {
    }
}
