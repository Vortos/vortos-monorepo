<?php

declare(strict_types=1);

namespace Vortos\Search\Query;

/**
 * What is being asked — the raw terms plus optional type filter and result bounds. Immutable;
 * pair it with a {@see SearchScope} (who is asking) when calling the query service.
 */
final class SearchQuery
{
    public const DEFAULT_LIMIT = 40;
    public const MAX_LIMIT     = 100;

    /** @var list<string> */
    public readonly array $types;
    public readonly int $limit;

    /**
     * @param string       $terms raw user input
     * @param list<string> $types restrict to these doc types; empty = all types
     * @param int          $limit total hits to return, clamped to [1, MAX_LIMIT]
     */
    public function __construct(
        public readonly string $terms,
        array $types = [],
        int $limit = self::DEFAULT_LIMIT,
    ) {
        $this->types = array_values(array_unique(array_filter($types, static fn ($t) => $t !== '')));
        $this->limit = max(1, min(self::MAX_LIMIT, $limit));
    }

    public function normalisedTerms(): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->terms) ?? '');
    }

    public function isBlank(): bool
    {
        return $this->normalisedTerms() === '';
    }

    /** Stable fingerprint of the question — used with the scope fingerprint to key the cache. */
    public function cacheFingerprint(): string
    {
        return substr(hash('xxh128', implode('|', [
            $this->normalisedTerms(),
            implode(',', $this->types),
            (string) $this->limit,
        ])), 0, 16);
    }
}
