<?php

declare(strict_types=1);

namespace Vortos\Search\Projection;

use Vortos\Search\Document\SearchDocument;

/**
 * Projection outcome: (re)index this document. Emitted by a {@see SearchableProjection} when a
 * domain event creates or mutates a searchable aggregate. Idempotent — the writer upserts on
 * (tenant, type, entityId).
 */
final class SearchUpsert
{
    public function __construct(public readonly SearchDocument $document)
    {
    }
}
