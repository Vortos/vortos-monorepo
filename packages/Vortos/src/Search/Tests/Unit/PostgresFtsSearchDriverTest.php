<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Search\Index\PostgresFtsSearchDriver;

final class PostgresFtsSearchDriverTest extends TestCase
{
    public function testBlankTermsMatchEverything(): void
    {
        self::assertTrue((new PostgresFtsSearchDriver())->compile('')->matchesEverything());
    }

    public function testUsesTsvectorMatchWithTrigramFallback(): void
    {
        $predicate = (new PostgresFtsSearchDriver())->compile('Johnson', 'q');

        self::assertStringContainsString("search_vector @@ websearch_to_tsquery('simple', :q)", $predicate->whereSql);
        self::assertStringContainsString('word_similarity(:q, keywords)', $predicate->whereSql);
        self::assertSame('Johnson', $predicate->params['q']);
    }

    public function testRanksByTsRankPlusSimilarity(): void
    {
        $predicate = (new PostgresFtsSearchDriver())->compile('Johnson', 'q');

        self::assertStringContainsString('ts_rank_cd(search_vector', $predicate->rankSql);
        self::assertStringContainsString('word_similarity(:q, keywords)', $predicate->rankSql);
    }
}
