<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Search\Index\PortableLikeSearchDriver;

final class PortableLikeSearchDriverTest extends TestCase
{
    public function testBlankTermsMatchEverything(): void
    {
        $predicate = (new PortableLikeSearchDriver())->compile('   ');

        self::assertTrue($predicate->matchesEverything());
        self::assertSame('', $predicate->whereSql);
        self::assertSame([], $predicate->params);
    }

    public function testWrapsTermsAsCaseInsensitiveLike(): void
    {
        $predicate = (new PortableLikeSearchDriver())->compile('Johnson', 'q');

        self::assertStringContainsString('LOWER(title) LIKE LOWER(:q)', $predicate->whereSql);
        self::assertStringContainsString('LOWER(body) LIKE LOWER(:q)', $predicate->whereSql);
        self::assertSame('%Johnson%', $predicate->params['q']);
    }

    public function testEscapesLikeWildcardsInUserInput(): void
    {
        $predicate = (new PortableLikeSearchDriver())->compile('50%_off', 'q');

        // % and _ must be escaped so they match literally, not as wildcards.
        self::assertSame('%50\\%\\_off%', $predicate->params['q']);
        self::assertStringContainsString("ESCAPE '\\'", $predicate->whereSql);
    }

    public function testRankBoostsTitleOverBody(): void
    {
        $predicate = (new PortableLikeSearchDriver())->compile('cup', 'q');

        self::assertStringContainsString('WHEN LOWER(title) LIKE', $predicate->rankSql);
        self::assertStringContainsString('THEN 3', $predicate->rankSql);
    }
}
