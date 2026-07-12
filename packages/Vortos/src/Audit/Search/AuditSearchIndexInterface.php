<?php

declare(strict_types=1);

namespace Vortos\Audit\Search;

/**
 * Pluggable free-text search over the audit trail.
 *
 * The default {@see PostgresFtsSearchIndex} (Postgres full-text) and portable
 * {@see LikeSearchIndex} both express search as a SQL WHERE fragment the keyset reader
 * ANDs into its single paginated query — no separate id-materialisation round-trip, so
 * search composes with every other filter and with cursor pagination for free.
 *
 * Apps can swap an external engine (OpenSearch/Elastic/Meilisearch) by implementing this
 * against their own index and returning a `chain_key`/`id` membership fragment.
 */
interface AuditSearchIndexInterface
{
    /**
     * Build the WHERE fragment matching $terms, plus its bound parameters.
     *
     * @param string $terms    the raw user search string
     * @param string $paramKey a unique bind-parameter name to use (caller guarantees uniqueness)
     *
     * @return array{string, array<string, mixed>} [sqlFragment, params]; a fragment of ''
     *                                              means "match everything" (blank search)
     */
    public function matchCondition(string $terms, string $paramKey = 'q'): array;
}
