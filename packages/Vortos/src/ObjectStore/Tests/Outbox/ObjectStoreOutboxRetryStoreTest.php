<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRetryStore;
use Vortos\ObjectStore\Outbox\OutboxStatus;

final class ObjectStoreOutboxRetryStoreTest extends TestCase
{
    private const TABLE = 'object_store_outbox';

    private function makeStore(Connection $connection): ObjectStoreOutboxRetryStore
    {
        return new ObjectStoreOutboxRetryStore($connection, self::TABLE);
    }

    public function test_count_dead_returns_zero_when_no_dead_rows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');

        $this->assertSame(0, $this->makeStore($connection)->countDead());
    }

    public function test_count_dead_returns_correct_count(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('4');

        $this->assertSame(4, $this->makeStore($connection)->countDead());
    }

    public function test_count_dead_filters_by_operation(): void
    {
        $capturedSql    = '';
        $capturedParams = [];
        $connection     = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
            $capturedSql    = $sql;
            $capturedParams = $params;
            return '0';
        });

        $this->makeStore($connection)->countDead(operation: 'put');

        $this->assertSame('put', $capturedParams['operation']);
        $this->assertStringContainsString('operation = :operation', $capturedSql);
        $this->assertSame(OutboxStatus::Dead->value, $capturedParams['status']);
    }

    public function test_count_dead_filters_by_date_range(): void
    {
        $capturedParams = [];
        $connection     = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return '0';
        });

        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to   = new \DateTimeImmutable('2026-06-01 00:00:00');
        $this->makeStore($connection)->countDead(createdFrom: $from, createdTo: $to);

        $this->assertSame('2026-01-01 00:00:00', $capturedParams['createdFrom']);
        $this->assertSame('2026-06-01 00:00:00', $capturedParams['createdTo']);
    }

    public function test_reset_dead_sets_status_to_pending_and_clears_attempt_count(): void
    {
        $capturedSql    = '';
        $capturedParams = [];
        $connection     = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
            $capturedSql    = $sql;
            $capturedParams = $params;
            return 2;
        });

        $result = $this->makeStore($connection)->resetDead(10);

        $this->assertSame(2, $result);
        $this->assertSame(OutboxStatus::Pending->value, $capturedParams['newStatus']);
        $this->assertStringContainsString('attempt_count = 0', $capturedSql);
        $this->assertStringContainsString('processed_at = NULL', $capturedSql);
        $this->assertStringContainsString('last_error = NULL', $capturedSql);
    }

    public function test_reset_dead_clears_next_attempt_at(): void
    {
        $capturedSql = '';
        $connection  = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$capturedSql) {
            $capturedSql = $sql;
            return 1;
        });

        $this->makeStore($connection)->resetDead(10);

        $this->assertStringContainsString('next_attempt_at = NULL', $capturedSql);
    }

    public function test_reset_dead_respects_limit(): void
    {
        $capturedSql = '';
        $connection  = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$capturedSql) {
            $capturedSql = $sql;
            return 5;
        });

        $this->makeStore($connection)->resetDead(25);

        $this->assertStringContainsString('LIMIT :limit', $capturedSql);
    }
}
