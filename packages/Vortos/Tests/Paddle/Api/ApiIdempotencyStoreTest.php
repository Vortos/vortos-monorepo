<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\ApiIdempotencyStore;

final class ApiIdempotencyStoreTest extends TestCase
{
    private function makeStore(Connection $connection): ApiIdempotencyStore
    {
        return new ApiIdempotencyStore($connection, 'paddle_idempotency_keys', 86400);
    }

    public function test_generate_key_executes_insert(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('INSERT INTO paddle_idempotency_keys'))
            ->willReturn(1);

        $store = $this->makeStore($connection);
        $key   = $store->generateKey('create_subscription');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $key,
        );
    }

    public function test_generated_keys_are_unique(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);

        $store = $this->makeStore($connection);
        $keys  = [];
        for ($i = 0; $i < 10; $i++) {
            $keys[] = $store->generateKey('operation');
        }

        $this->assertCount(10, array_unique($keys));
    }

    public function test_prune_expired_executes_delete(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('DELETE FROM paddle_idempotency_keys'))
            ->willReturn(3);

        $store  = $this->makeStore($connection);
        $result = $store->pruneExpired();
        $this->assertSame(3, $result);
    }

    public function test_custom_table_name_used_in_query(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('my_table'))
            ->willReturn(1);

        $store = new ApiIdempotencyStore($connection, 'my_table', 86400);
        $store->generateKey('op');
    }

    public function test_generated_key_is_uuidv4_format(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);

        $store = $this->makeStore($connection);
        $key   = $store->generateKey('test');

        $parts = explode('-', $key);
        $this->assertCount(5, $parts);
        $this->assertSame('4', $parts[2][0]); // version 4
    }
}
