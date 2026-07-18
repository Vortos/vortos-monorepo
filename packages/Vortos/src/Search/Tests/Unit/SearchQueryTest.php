<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Search\Query\SearchQuery;
use Vortos\Search\Query\SearchScope;

final class SearchQueryTest extends TestCase
{
    public function testClampsLimitToBounds(): void
    {
        self::assertSame(SearchQuery::MAX_LIMIT, (new SearchQuery('x', limit: 9999))->limit);
        self::assertSame(1, (new SearchQuery('x', limit: 0))->limit);
    }

    public function testNormalisesAndDetectsBlankTerms(): void
    {
        self::assertSame('a b', (new SearchQuery("  a   b \n"))->normalisedTerms());
        self::assertTrue((new SearchQuery("   \t "))->isBlank());
        self::assertFalse((new SearchQuery('a'))->isBlank());
    }

    public function testDeduplicatesTypeFilter(): void
    {
        self::assertSame(['application', 'payment'], (new SearchQuery('x', ['application', 'payment', 'application', '']))->types);
    }

    public function testQueryFingerprintIsStableAndTermSensitive(): void
    {
        self::assertSame((new SearchQuery('abc'))->cacheFingerprint(), (new SearchQuery('abc'))->cacheFingerprint());
        self::assertNotSame((new SearchQuery('abc'))->cacheFingerprint(), (new SearchQuery('abd'))->cacheFingerprint());
    }

    public function testScopeFingerprintDiffersByPermissions(): void
    {
        $a = new SearchScope('org-1', 'm-1', ['entries.view.any']);
        $b = new SearchScope('org-1', 'm-1', ['payments.view.any']);

        self::assertNotSame($a->cacheFingerprint(), $b->cacheFingerprint());
    }

    public function testScopeFingerprintIgnoresPermissionOrder(): void
    {
        $a = new SearchScope('org-1', 'm-1', ['a', 'b']);
        $b = new SearchScope('org-1', 'm-1', ['b', 'a']);

        self::assertSame($a->cacheFingerprint(), $b->cacheFingerprint());
    }

    public function testNonSuperuserScopeRequiresTenant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchScope('');
    }

    public function testSuperuserScopeMayOmitTenant(): void
    {
        self::assertTrue((new SearchScope('', superuser: true))->superuser);
    }
}
