<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Search\LikeSearchIndex;
use Vortos\Audit\Search\PostgresFtsSearchIndex;

final class AuditSearchIndexTest extends TestCase
{
    public function test_like_index_blank_terms_match_everything(): void
    {
        self::assertSame(['', []], (new LikeSearchIndex())->matchCondition('   '));
    }

    public function test_like_index_builds_or_across_columns_with_escaped_wildcards(): void
    {
        [$sql, $params] = (new LikeSearchIndex())->matchCondition('50%_off', 'q');

        self::assertStringContainsString('LOWER(actor) LIKE LOWER(:q)', $sql);
        self::assertStringContainsString('LOWER(context) LIKE LOWER(:q)', $sql);
        self::assertStringContainsString(" ESCAPE '\\'", $sql);
        // % and _ escaped so they match literally, not as wildcards.
        self::assertSame('%50\\%\\_off%', $params['q']);
    }

    public function test_pg_fts_blank_terms_match_everything(): void
    {
        self::assertSame(['', []], (new PostgresFtsSearchIndex())->matchCondition(''));
    }

    public function test_pg_fts_builds_tsquery_predicate(): void
    {
        [$sql, $params] = (new PostgresFtsSearchIndex())->matchCondition('refund captured', 'q');

        self::assertStringContainsString("to_tsvector('simple'", $sql);
        self::assertStringContainsString("@@ plainto_tsquery('simple', :q)", $sql);
        self::assertSame('refund captured', $params['q']);
    }
}
