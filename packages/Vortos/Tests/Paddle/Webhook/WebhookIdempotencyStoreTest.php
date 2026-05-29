<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Webhook;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Webhook\WebhookIdempotencyStore;

final class WebhookIdempotencyStoreTest extends TestCase
{
    private function makeStore(Connection $connection): WebhookIdempotencyStore
    {
        return new WebhookIdempotencyStore($connection, 'paddle_webhook_idempotency', 259200);
    }

    public function test_has_been_processed_returns_false_when_no_record(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');

        $store = $this->makeStore($connection);
        $this->assertFalse($store->hasBeenProcessed('evt_01'));
    }

    public function test_has_been_processed_returns_true_when_record_exists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('1');

        $store = $this->makeStore($connection);
        $this->assertTrue($store->hasBeenProcessed('evt_01'));
    }

    public function test_mark_processed_executes_insert(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INSERT INTO paddle_webhook_idempotency'))
            ->willReturn(1);

        $store = $this->makeStore($connection);
        $store->markProcessed('evt_01', 'subscription.created');
    }

    public function test_prune_expired_executes_delete(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('DELETE FROM paddle_webhook_idempotency'))
            ->willReturn(5);

        $store = $this->makeStore($connection);
        $result = $store->pruneExpired();
        $this->assertSame(5, $result);
    }

    public function test_query_uses_correct_table_name(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('my_custom_table'))
            ->willReturn('0');

        $store = new WebhookIdempotencyStore($connection, 'my_custom_table', 259200);
        $store->hasBeenProcessed('evt_01');
    }
}
