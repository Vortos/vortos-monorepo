<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\Dbal\DbalAuditQueryReader;
use Vortos\Audit\Search\LikeSearchIndex;

/**
 * Verifies the SQL the reader emits for the F2 filters (action prefix + free-text search),
 * the impersonation filter, and the facet aggregation — without a database, by capturing the
 * query passed to DBAL.
 */
final class DbalAuditQueryReaderSqlTest extends TestCase
{
    public function test_page_applies_action_prefix_and_search_conditions(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [];
            },
        );

        (new DbalAuditQueryReader($conn, 'vortos_audit_events', new LikeSearchIndex()))->page(new AuditQuery(
            scope:        Scope::Tenant,
            tenantId:     'org-1',
            actionPrefix: 'payment.',
            search:       'refund',
        ));

        self::assertStringContainsString("action LIKE :action_prefix ESCAPE '\\'", (string) $capturedSql);
        self::assertSame('payment.%', $capturedParams['action_prefix']);
        self::assertStringContainsString('LIKE LOWER(:search_q)', (string) $capturedSql);
        self::assertSame('%refund%', $capturedParams['search_q']);
        self::assertStringContainsString('ORDER BY occurred_at DESC, id DESC', (string) $capturedSql);
    }

    public function test_impersonated_only_filters_on_the_on_behalf_of_chain(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [];
            },
        );

        (new DbalAuditQueryReader($conn, 'vortos_audit_events'))->page(new AuditQuery(
            scope:            Scope::Platform,
            impersonatedOnly: true,
        ));

        self::assertStringContainsString('actor LIKE :impersonated', (string) $capturedSql);
        // The discriminator is the VALUE: toArray() always emits the key, as null when direct.
        self::assertSame('%"on_behalf_of":{%', $capturedParams['impersonated']);
        self::assertSame(DbalAuditQueryReader::IMPERSONATED_LIKE, $capturedParams['impersonated']);
    }

    public function test_impersonated_only_is_off_by_default(): void
    {
        $capturedSql = null;
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;
                return [];
            },
        );

        (new DbalAuditQueryReader($conn, 'vortos_audit_events'))->page(new AuditQuery(scope: Scope::Platform));

        self::assertStringNotContainsString(':impersonated', (string) $capturedSql);
    }

    public function test_impersonated_only_survives_the_positional_rebuilds(): void
    {
        // withCursor()/withLimit() rebuild AuditQuery POSITIONALLY. A field added to the end of
        // the constructor and forgotten in either rebuild is silently dropped — and the streaming
        // exporter calls BOTH on every page, so an impersonation export would quietly widen to
        // the whole trail from page 2 onward.
        $query = new AuditQuery(scope: Scope::Platform, impersonatedOnly: true);

        self::assertTrue($query->withCursor(new \Vortos\Audit\Query\AuditCursor('2026-08-01T00:00:00.000000+00:00', 'a'))->impersonatedOnly);
        self::assertTrue($query->withLimit(10)->impersonatedOnly);
    }

    public function test_the_partial_index_predicate_matches_the_reader_predicate(): void
    {
        // Postgres only uses a partial index when the query predicate matches the index
        // predicate; a drift between these two silently costs a sequential scan.
        $sql = null;
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturnCallback(function (string $s) use (&$sql): int {
            $sql = $s;
            return 0;
        });

        (new \Vortos\Audit\Storage\Dbal\Postgres\PostgresAuditExtrasInstaller($conn, 'vortos_audit_events'))
            ->installImpersonationIndex();

        self::assertStringContainsString(
            "WHERE actor LIKE '" . DbalAuditQueryReader::IMPERSONATED_LIKE . "'",
            (string) $sql,
        );
        self::assertStringContainsString('vortos_audit_events_impersonated', (string) $sql);
    }

    public function test_facets_group_by_each_dimension_and_drop_the_cursor(): void
    {
        $seen = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use (&$seen): array {
                $seen[] = [$sql, $params];
                if (str_contains($sql, 'GROUP BY action')) {
                    return [['k' => 'payment.captured', 'c' => 3], ['k' => 'payment.refunded', 'c' => 1]];
                }
                return [];
            },
        );

        $facets = (new DbalAuditQueryReader($conn, 'vortos_audit_events'))->facets(new AuditQuery(
            scope:  Scope::Platform,
            cursor: new \Vortos\Audit\Query\AuditCursor('2026-07-01T00:00:00.000000+00:00', 'x'),
        ));

        $joined = implode('||', array_map(static fn ($s) => $s[0], $seen));
        self::assertStringContainsString('GROUP BY action', $joined);
        self::assertStringContainsString('GROUP BY sensitivity', $joined);
        self::assertStringContainsString('GROUP BY outcome', $joined);
        // Cursor is dropped before counting, so no keyset predicate leaks into the facet query.
        self::assertStringNotContainsString('cur_ts', $joined);
        self::assertSame(['payment.captured' => 3, 'payment.refunded' => 1], $facets->byAction);
    }
}
