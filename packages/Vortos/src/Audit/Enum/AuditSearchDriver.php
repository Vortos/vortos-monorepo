<?php

declare(strict_types=1);

namespace Vortos\Audit\Enum;

/**
 * Which search index backs free-text audit queries.
 *
 *   - PostgresFts: default. A tsvector/GIN index over actor label + action + target +
 *                  context, maintained on write. Best fit for the Postgres-first store.
 *   - None:        no index; free-text falls back to a bounded LIKE scan. Correct for
 *                  non-Postgres stores or low-volume trails where an index isn't worth it.
 *   - External:    the app supplies its own {@see \Vortos\Audit\Search\AuditSearchIndexInterface}
 *                  (OpenSearch, Elastic, Meilisearch, …); the framework wires nothing.
 */
enum AuditSearchDriver: string
{
    case PostgresFts = 'postgres_fts';
    case None        = 'none';
    case External    = 'external';
}
