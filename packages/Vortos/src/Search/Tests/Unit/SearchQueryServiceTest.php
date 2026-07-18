<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Search\Cache\SearchCacheInterface;
use Vortos\Search\Query\SearchHit;
use Vortos\Search\Query\SearchQuery;
use Vortos\Search\Query\SearchQueryService;
use Vortos\Search\Query\SearchReaderInterface;
use Vortos\Search\Query\SearchResults;
use Vortos\Search\Query\SearchScope;

final class SearchQueryServiceTest extends TestCase
{
    public function testBlankQueryShortCircuitsWithoutHittingReader(): void
    {
        $reader = new class implements SearchReaderInterface {
            public int $calls = 0;
            public function search(SearchQuery $q, SearchScope $s): SearchResults
            {
                $this->calls++;
                return SearchResults::empty();
            }
        };

        $service = new SearchQueryService($reader);
        $results = $service->search(new SearchQuery('   '), new SearchScope('org-1'));

        self::assertTrue($results->isEmpty());
        self::assertSame(0, $reader->calls);
    }

    public function testCacheHitSkipsReaderAndFlagsFromCache(): void
    {
        $hit    = new SearchHit('application', '1', 'Sarah', 'Pending', '/applications/1', 1.0);
        $cached = new SearchResults([$hit]);

        $reader = new class implements SearchReaderInterface {
            public int $calls = 0;
            public function search(SearchQuery $q, SearchScope $s): SearchResults
            {
                $this->calls++;
                return SearchResults::empty();
            }
        };
        $cache = new class ($cached) implements SearchCacheInterface {
            public function __construct(private SearchResults $seed)
            {
            }
            public function get(string $key): ?SearchResults
            {
                return $this->seed;
            }
            public function put(string $key, SearchResults $results, int $ttl): void
            {
            }
        };

        $results = (new SearchQueryService($reader, $cache))->search(new SearchQuery('sarah'), new SearchScope('org-1'));

        self::assertSame(0, $reader->calls);
        self::assertTrue($results->fromCache);
        self::assertCount(1, $results->hits);
    }

    public function testMissWritesThroughToCache(): void
    {
        $fromReader = new SearchResults([new SearchHit('application', '1', 'Sarah', '', '/applications/1', 1.0)]);

        $reader = new class ($fromReader) implements SearchReaderInterface {
            public function __construct(private SearchResults $r)
            {
            }
            public function search(SearchQuery $q, SearchScope $s): SearchResults
            {
                return $this->r;
            }
        };
        $cache = new class implements SearchCacheInterface {
            public array $stored = [];
            public function get(string $key): ?SearchResults
            {
                return null;
            }
            public function put(string $key, SearchResults $results, int $ttl): void
            {
                $this->stored[$key] = $ttl;
            }
        };

        $results = (new SearchQueryService($reader, $cache, cacheTtlSeconds: 30))->search(
            new SearchQuery('sarah'),
            new SearchScope('org-1'),
        );

        self::assertFalse($results->fromCache);
        self::assertCount(1, $cache->stored);
        self::assertSame([30], array_values($cache->stored));
    }
}
