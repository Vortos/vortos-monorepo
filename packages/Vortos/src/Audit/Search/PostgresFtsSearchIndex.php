<?php

declare(strict_types=1);

namespace Vortos\Audit\Search;

/**
 * Postgres full-text search over the searchable columns.
 *
 * Builds a `to_tsvector(...) @@ plainto_tsquery(...)` predicate over actor label + action +
 * target + context. Correct with or without a supporting index; a matching expression GIN
 * index (`USING gin (to_tsvector('simple', ...))`, installed out-of-band because the portable
 * Schema-diff migration seam can't express it) turns it from a scan into an index probe.
 *
 * Uses the 'simple' text-search config (no stemming/stop-words) so identifier-like tokens —
 * action keys, ids, ip fragments — match verbatim, which is what an audit console wants.
 */
final class PostgresFtsSearchIndex implements AuditSearchIndexInterface
{
    /** Column expression indexed + queried; kept in one place so the DDL and the query agree. */
    public const DOCUMENT_SQL =
        "to_tsvector('simple', coalesce(actor,'') || ' ' || coalesce(action,'') || ' ' || coalesce(target,'') || ' ' || coalesce(context,''))";

    public function matchCondition(string $terms, string $paramKey = 'q'): array
    {
        $terms = trim($terms);
        if ($terms === '') {
            return ['', []];
        }

        return [
            self::DOCUMENT_SQL . " @@ plainto_tsquery('simple', :{$paramKey})",
            [$paramKey => $terms],
        ];
    }
}
