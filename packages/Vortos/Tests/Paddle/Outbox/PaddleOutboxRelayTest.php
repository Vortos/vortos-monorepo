<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Paddle\SDK\Exceptions\ApiError;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Paddle\Outbox\PaddleOutboxDispatcherInterface;
use Vortos\Paddle\Outbox\PaddleOutboxRelay;

final class PaddleOutboxRelayTest extends TestCase
{
    private function makeOutboxRow(
        int $id = 1,
        string $operation = 'subscription.update',
        array $payload = ['id' => 'sub_123'],
        int $attempts = 0,
    ): array {
        return [
            'id'        => $id,
            'operation' => $operation,
            'payload'   => json_encode($payload),
            'attempts'  => $attempts,
        ];
    }

    private function makeResult(array $rows): Result
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        return $result;
    }

    public function test_relay_delivers_to_dispatcher(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([
            $this->makeOutboxRow(1, 'subscription.update', ['id' => 'sub_123']),
        ]));
        $connection->expects($this->once())->method('executeStatement');

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->expects($this->once())
                   ->method('dispatch')
                   ->with('subscription.update', ['id' => 'sub_123']);

        $relay = new PaddleOutboxRelay($connection, $dispatcher, new NullLogger());
        $count = $relay->relay();

        $this->assertSame(1, $count);
    }

    public function test_relay_returns_zero_when_no_rows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([]));

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $relay = new PaddleOutboxRelay($connection, $dispatcher, new NullLogger());
        $count = $relay->relay();

        $this->assertSame(0, $count);
    }

    public function test_relay_schedules_backoff_on_rate_limit_429(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([
            $this->makeOutboxRow(),
        ]));
        $connection->expects($this->once())->method('executeStatement');

        $apiError              = ApiError::fromErrorData([
            'type'              => 'request_error',
            'code'              => 'rate_limit_exceeded',
            'detail'            => 'Too many requests.',
            'documentation_url' => 'https://developer.paddle.com',
        ], retryAfter: 30);

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException($apiError);

        $relay = new PaddleOutboxRelay($connection, $dispatcher, new NullLogger());
        $count = $relay->relay();

        $this->assertSame(0, $count);
    }

    public function test_relay_marks_failed_on_api_error_without_retry_after(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([
            $this->makeOutboxRow(),
        ]));
        $connection->expects($this->once())->method('executeStatement');

        $apiError = ApiError::fromErrorData([
            'type'              => 'request_error',
            'code'              => 'invalid_subscription',
            'detail'            => 'Subscription not found.',
            'documentation_url' => 'https://developer.paddle.com',
        ]);

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException($apiError);

        $relay = new PaddleOutboxRelay($connection, $dispatcher, new NullLogger());
        $count = $relay->relay();

        $this->assertSame(0, $count);
    }

    public function test_relay_retries_on_transient_error(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([
            $this->makeOutboxRow(attempts: 0),
        ]));
        $connection->expects($this->once())->method('executeStatement');

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Network error'));

        $relay = new PaddleOutboxRelay($connection, $dispatcher, new NullLogger());
        $count = $relay->relay();

        $this->assertSame(0, $count);
    }

    public function test_relay_permanently_fails_after_max_attempts(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->makeResult([
            $this->makeOutboxRow(attempts: 4),
        ]));
        $connection->expects($this->once())->method('executeStatement');

        $dispatcher = $this->createMock(PaddleOutboxDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Persistent error'));

        $relay = new PaddleOutboxRelay($connection, $dispatcher, new NullLogger());
        $count = $relay->relay();

        $this->assertSame(0, $count);
    }
}
