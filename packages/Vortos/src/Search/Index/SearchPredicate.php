<?php

declare(strict_types=1);

namespace Vortos\Search\Index;

/**
 * A compiled search expression: the WHERE fragment that matches the user's terms, an optional
 * numeric rank expression to order by (higher = more relevant), and the bound parameters both
 * reference. Produced by a {@see SearchIndexDriver} and composed by the reader into its single
 * scoped, paginated query — so matching and ranking stay driver-specific while tenant/owner/
 * permission scoping and pagination stay driver-agnostic.
 */
final class SearchPredicate
{
    /**
     * @param string                $whereSql SQL boolean fragment; '' means "match everything" (blank query)
     * @param string                $rankSql  numeric SQL expression, higher = better; '' means "no relevance order"
     * @param array<string, mixed>  $params   bound parameters referenced by whereSql/rankSql
     */
    public function __construct(
        public readonly string $whereSql = '',
        public readonly string $rankSql = '',
        public readonly array $params = [],
    ) {
    }

    public function matchesEverything(): bool
    {
        return $this->whereSql === '';
    }
}
