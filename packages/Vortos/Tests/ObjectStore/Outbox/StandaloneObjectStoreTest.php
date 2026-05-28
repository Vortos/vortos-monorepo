<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Outbox\StandaloneObjectStore;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\ObjectKey;

final class StandaloneObjectStoreTest extends TestCase
{
    public function test_mutating_operation_opens_own_transaction_when_none_is_active(): void
    {
        $result = new DeleteResult(new ObjectKey('tmp/a.txt'), false);
        $store = $this->createMock(ObjectStoreInterface::class);
        $store->expects($this->once())->method('delete')->with('tmp/a.txt')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());

        $this->assertSame($result, (new StandaloneObjectStore($connection, $store))->delete('tmp/a.txt'));
    }

    public function test_read_operation_does_not_open_transaction(): void
    {
        $store = $this->createMock(ObjectStoreInterface::class);
        $store->expects($this->once())->method('exists')->with('tmp/a.txt')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('transactional');

        $this->assertTrue((new StandaloneObjectStore($connection, $store))->exists('tmp/a.txt'));
    }
}
