<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Outbox\OutboxStatus;
use Vortos\Paddle\Outbox\PaddleOutboxRetryStore;

final class PaddleOutboxRetryStoreTest extends TestCase
{
    private const TABLE = 'paddle_outbox';

    private function makeStore(Connection $connection): PaddleOutboxRetryStore
    {
        return new PaddleOutboxRetryStore($connection, self::TABLE);
    }

    public function test_count_failed_returns_zero_when_no_failed_rows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');

        $this->assertSame(0, $this->makeStore($connection)->countFailed());
    }

    public function test_count_failed_returns_correct_count(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('7');

        $this->assertSame(7, $this->makeStore($connection)->countFailed());
    }

    public function test_count_failed_includes_status_failed_in_query(): void
    {
        $capturedSql    = '';
        $capturedParams = [];
        $connection     = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
            $capturedSql    = $sql;
            $capturedParams = $params;
            return '0';
        });

        $this->makeStore($connection)->countFailed();

        $this->assertSame(OutboxStatus::Failed->value, $capturedParams['status']);
    }

    public function test_count_failed_filters_by_operation(): void
    {
        $capturedSql    = '';
        $capturedParams = [];
        $connection     = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
            $capturedSql    = $sql;
            $capturedParams = $params;
            return '2';
        });

        $this->makeStore($connection)->countFailed(operation: 'customer.create');

        $this->assertSame('customer.create', $capturedParams['operation']);
        $this->assertStringContainsString('operation = :operation', $capturedSql);
    }

    public function test_count_failed_filters_by_id(): void
    {
        $capturedSql    = '';
        $capturedParams = [];
        $connection     = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
            $capturedSql    = $sql;
            $capturedParams = $params;
            return '1';
        });

        $this->makeStore($connection)->countFailed(id: 42);

        $this->assertSame(42, $capturedParams['id']);
        $this->assertStringContainsString('id = :id', $capturedSql);
    }

    public function test_reset_failed_sets_status_to_pending_and_clears_error(): void
    {
        $capturedSql    = '';
        $capturedParams = [];
        $connection     = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
            $capturedSql    = $sql;
            $capturedParams = $params;
            return 3;
        });

        $result = $this->makeStore($connection)->resetFailed(10);

        $this->assertSame(3, $result);
        $this->assertSame(OutboxStatus::Pending->value, $capturedParams['newStatus']);
        $this->assertStringContainsString('attempts = 0', $capturedSql);
        $this->assertStringContainsString('failed_at = NULL', $capturedSql);
        $this->assertStringContainsString('last_error = NULL', $capturedSql);
    }

    public function test_reset_failed_includes_next_attempt_at_now(): void
    {
        $capturedParams = [];
        $connection     = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return 1;
        });

        $before = new \DateTimeImmutable(date('Y-m-d H:i:s'));
        $this->makeStore($connection)->resetFailed(10);
        $after  = new \DateTimeImmutable(date('Y-m-d H:i:s'));

        $next = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $capturedParams['now']);
        $this->assertGreaterThanOrEqual($before, $next);
        $this->assertLessThanOrEqual($after, $next);
    }

    public function test_reset_failed_respects_limit(): void
    {
        $capturedSql = '';
        $connection  = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$capturedSql) {
            $capturedSql = $sql;
            return 5;
        });

        $this->makeStore($connection)->resetFailed(25);

        $this->assertStringContainsString('LIMIT :limit', $capturedSql);
    }
}
