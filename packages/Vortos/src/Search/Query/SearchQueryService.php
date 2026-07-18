<?php

declare(strict_types=1);

namespace Vortos\Search\Query;

use Psr\Clock\ClockInterface;
use Vortos\Search\Cache\NullSearchCache;
use Vortos\Search\Cache\SearchCacheInterface;
use Vortos\Search\Observability\NullSearchMetrics;
use Vortos\Search\Observability\SearchMetricsInterface;

/**
 * The application-facing entry point for reading the index: cache → reader, with metrics.
 *
 * Blank queries short-circuit to empty (a global search box shouldn't dump the whole tenant).
 * The cache key folds together WHAT is asked ({@see SearchQuery}) and WHO asks
 * ({@see SearchScope}), so two callers with different permissions never share an entry — the
 * cache can't become a scope-bypass.
 */
final class SearchQueryService
{
    public function __construct(
        private readonly SearchReaderInterface $reader,
        private readonly SearchCacheInterface $cache = new NullSearchCache(),
        private readonly SearchMetricsInterface $metrics = new NullSearchMetrics(),
        private readonly ?ClockInterface $clock = null,
        private readonly int $cacheTtlSeconds = 15,
    ) {
    }

    public function search(SearchQuery $query, SearchScope $scope): SearchResults
    {
        if ($query->isBlank()) {
            return SearchResults::empty();
        }

        $start = $this->now();
        $key   = $this->cacheKey($query, $scope);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $results = $cached->withCacheFlag(true);
            $this->metrics->queryObserved(!$results->isEmpty(), true, $this->now() - $start);

            return $results;
        }

        $results = $this->reader->search($query, $scope);

        if ($this->cacheTtlSeconds > 0) {
            $this->cache->put($key, $results, $this->cacheTtlSeconds);
        }

        $this->metrics->queryObserved(!$results->isEmpty(), false, $this->now() - $start);

        return $results;
    }

    private function cacheKey(SearchQuery $query, SearchScope $scope): string
    {
        return 'vortos:search:' . $scope->cacheFingerprint() . ':' . $query->cacheFingerprint();
    }

    private function now(): float
    {
        if ($this->clock !== null) {
            return (float) $this->clock->now()->format('U.u');
        }

        return microtime(true);
    }
}
