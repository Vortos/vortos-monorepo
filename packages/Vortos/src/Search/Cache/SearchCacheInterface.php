<?php

declare(strict_types=1);

namespace Vortos\Search\Cache;

use Vortos\Search\Query\SearchResults;

/**
 * Short-TTL cache for hot, repeated queries. Keys are already namespaced by tenant + principal
 * fingerprint by the query service, so a cached entry can never leak across callers. A miss
 * returns null; the default {@see NullSearchCache} always misses.
 */
interface SearchCacheInterface
{
    public function get(string $key): ?SearchResults;

    public function put(string $key, SearchResults $results, int $ttlSeconds): void;
}
