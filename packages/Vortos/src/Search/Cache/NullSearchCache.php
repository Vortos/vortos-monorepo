<?php

declare(strict_types=1);

namespace Vortos\Search\Cache;

use Vortos\Search\Query\SearchResults;

/** No-op cache — always misses. The safe default when no Redis cache is configured. */
final class NullSearchCache implements SearchCacheInterface
{
    public function get(string $key): ?SearchResults
    {
        return null;
    }

    public function put(string $key, SearchResults $results, int $ttlSeconds): void
    {
        // intentionally nothing
    }
}
