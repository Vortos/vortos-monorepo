<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Paddle\SDK\Exceptions\ApiError;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Paddle\Outbox\OutboxStatus;
use Vortos\Paddle\Outbox\PaddleOutboxDispatcherInterface;
use Vortos\Paddle\Outbox\PaddleOutboxRelay;

final class PaddleOutboxRelayTest extends TestCase
{
    private const TABLE = 'paddle_outbox';

    private function makeRelay(
        Connection                      $connection,
        PaddleOutboxDispatcherInterface $dispatcher,
        int $batchSize          = 50,
        int $maxAttempts        = 5,
        int $backoffBaseSeconds = 60,
        int $backoffCapSeconds  = 3600,
    ): PaddleOutboxRelay {
        return new PaddleOutboxRelay(
            $connection,
            $dispatcher,
            new NullLogger(),
            self::TABLE,
            $batchSize,
            $maxAttempts,
            $backoffBaseSeconds,
            $backoffCapSeconds,
        );
    }

    private function makeResult(array $rows): Result
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        return $result;
    }

    private function makeRow(int $id = 1, string $operation = 'customer.create', array $payload = ['email' => 'a@b.com'], int $attempts = 0): array
    {
        return ['id' => $id, 'operation' => $operation, 'payload' => json_encode($payload), 'attempts' => $attempts];
    }

    public function test_relay_returns_zero_when_no_pending_rows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([]));

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $this->assertSame(0, $this->makeRelay($connection, $dispatcher)->relay());
    }

    public function test_relay_marks_row_as_delivered_on_success(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow()]));

        $executedSql = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$executedSql) {
            $executedSql[] = $sql;
            return 1;
        });

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);

        $count = $this->makeRelay($connection, $dispatcher)->relay();

        $this->assertSame(1, $count);
        $this->assertStringContainsString('delivered_at', $executedSql[0]);
        $this->assertStringContainsString('last_attempted_at', $executedSql[0]);
        $this->assertStringNotContainsString('failed_at', $executedSql[0]);
    }

    public function test_relay_fetch_query_filters_by_pending_status_not_failed_at(): void
    {
        $capturedSql = '';
        $connection  = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(function (string $sql) use (&$capturedSql) {
            $capturedSql = $sql;
            $result      = $this->createMock(Result::class);
            $result->method('fetchAllAssociative')->willReturn([]);
            return $result;
        });

        $this->makeRelay($connection, $this->createMock(PaddleOutboxDispatcherInterface::class))->relay();

        $this->assertStringContainsString('status = :status', $capturedSql);
        $this->assertStringNotContainsString('failed_at IS NULL', $capturedSql);
    }

    public function test_relay_stores_last_error_on_transient_failure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attempts: 0)]));

        $capturedParams = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return 1;
        });

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Connection refused'));

        $this->makeRelay($connection, $dispatcher)->relay();

        $this->assertSame('Connection refused', $capturedParams['error']);
    }

    public function test_relay_schedules_exponential_backoff_on_first_failure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attempts: 0)]));

        $capturedParams = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return 1;
        });

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Transient'));

        $before = new \DateTimeImmutable();
        $this->makeRelay($connection, $dispatcher, backoffBaseSeconds: 60)->relay();
        $after  = new \DateTimeImmutable();

        $next = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $capturedParams['next']);
        $this->assertGreaterThanOrEqual($before->modify('+59 seconds'), $next);
        $this->assertLessThanOrEqual($after->modify('+61 seconds'), $next);
    }

    public function test_relay_doubles_backoff_on_second_failure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attempts: 1)]));

        $capturedParams = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return 1;
        });

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Transient'));

        $before = new \DateTimeImmutable();
        $this->makeRelay($connection, $dispatcher, backoffBaseSeconds: 60)->relay();
        $after  = new \DateTimeImmutable();

        $next = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $capturedParams['next']);
        $this->assertGreaterThanOrEqual($before->modify('+119 seconds'), $next);
        $this->assertLessThanOrEqual($after->modify('+121 seconds'), $next);
    }

    public function test_relay_caps_backoff_at_cap_seconds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attempts: 10)]));

        $capturedParams = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return 1;
        });

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Transient'));

        $before = new \DateTimeImmutable();
        $this->makeRelay($connection, $dispatcher, maxAttempts: 20, backoffBaseSeconds: 60, backoffCapSeconds: 300)->relay();
        $after  = new \DateTimeImmutable();

        $next = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $capturedParams['next']);
        $this->assertGreaterThanOrEqual($before->modify('+299 seconds'), $next);
        $this->assertLessThanOrEqual($after->modify('+301 seconds'), $next);
    }

    public function test_relay_marks_failed_after_max_attempts_and_stores_error(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attempts: 4)]));

        $capturedSql    = '';
        $capturedParams = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
            $capturedSql    = $sql;
            $capturedParams = $params;
            return 1;
        });

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Permanent error'));

        $count = $this->makeRelay($connection, $dispatcher, maxAttempts: 5)->relay();

        $this->assertSame(0, $count);
        $this->assertStringContainsString('failed_at', $capturedSql);
        $this->assertSame('Permanent error', $capturedParams['error']);
        $this->assertSame(OutboxStatus::Failed->value, $capturedParams['status']);
    }

    public function test_relay_uses_retry_after_header_on_rate_limit(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow()]));

        $capturedParams = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return 1;
        });

        $apiError = ApiError::fromErrorData([
            'type' => 'request_error', 'code' => 'rate_limit_exceeded',
            'detail' => 'Too many requests.', 'documentation_url' => 'https://developer.paddle.com',
        ], retryAfter: 45);

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException($apiError);

        $before = new \DateTimeImmutable();
        $count  = $this->makeRelay($connection, $dispatcher)->relay();
        $after  = new \DateTimeImmutable();

        $this->assertSame(0, $count);
        $next = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $capturedParams['next']);
        $this->assertGreaterThanOrEqual($before->modify('+44 seconds'), $next);
        $this->assertLessThanOrEqual($after->modify('+46 seconds'), $next);
    }

    public function test_relay_marks_failed_immediately_on_non_retryable_api_error(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow()]));

        $capturedSql    = '';
        $capturedParams = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
            $capturedSql    = $sql;
            $capturedParams = $params;
            return 1;
        });

        $apiError = ApiError::fromErrorData([
            'type' => 'request_error', 'code' => 'not_found',
            'detail' => 'Resource not found.', 'documentation_url' => 'https://developer.paddle.com',
        ]);

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException($apiError);

        $count = $this->makeRelay($connection, $dispatcher)->relay();

        $this->assertSame(0, $count);
        $this->assertStringContainsString('failed_at', $capturedSql);
        $this->assertSame(OutboxStatus::Failed->value, $capturedParams['status']);
    }

    public function test_relay_processes_multiple_rows_and_returns_correct_count(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([
            $this->makeRow(1), $this->makeRow(2), $this->makeRow(3),
        ]));
        $connection->expects($this->exactly(3))->method('executeStatement');

        $this->assertSame(3, $this->makeRelay($connection, $this->createMock(PaddleOutboxDispatcherInterface::class))->relay());
    }

    public function test_relay_respects_configurable_max_attempts(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attempts: 2)]));

        $capturedSql = '';
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$capturedSql) {
            $capturedSql = $sql;
            return 1;
        });

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Error'));

        $this->makeRelay($connection, $dispatcher, maxAttempts: 3)->relay();

        $this->assertStringContainsString('failed_at', $capturedSql);
    }

    public function test_relay_respects_configurable_backoff_base(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attempts: 0)]));

        $capturedParams = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return 1;
        });

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Error'));

        $before = new \DateTimeImmutable();
        $this->makeRelay($connection, $dispatcher, backoffBaseSeconds: 10)->relay();
        $after  = new \DateTimeImmutable();

        $next = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $capturedParams['next']);
        $this->assertGreaterThanOrEqual($before->modify('+9 seconds'), $next);
        $this->assertLessThanOrEqual($after->modify('+11 seconds'), $next);
    }
}
