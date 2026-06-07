<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Outbox\ObjectOperationSerializer;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxWriter;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\Persistence\Transaction\TransactionRequiredException;

final class ObjectStoreOutboxWriterTest extends TestCase
{
    public function test_queue_inserts_pending_operation(): void
    {
        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = [$table, $data];
                return 1;
            });

        $writer = new ObjectStoreOutboxWriter($connection, new ObjectOperationSerializer(), 'object_store_outbox');
        $writer->queue(new ObjectStoreOperation('delete', ['key' => new ObjectKey('tmp/video.mp4')]), 'event-1');

        $this->assertSame('object_store_outbox', $captured[0]);
        $this->assertSame('event-1', $captured[1]['domain_event_id']);
        $this->assertSame('delete', $captured[1]['operation']);
        $this->assertSame('pending', $captured[1]['status']);
        $payload = json_decode($captured[1]['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('tmp/video.mp4', $payload['key']);
    }

    public function test_queue_requires_active_transaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->never())->method('insert');

        $writer = new ObjectStoreOutboxWriter($connection, new ObjectOperationSerializer(), 'object_store_outbox');

        $this->expectException(TransactionRequiredException::class);
        $writer->queue(new ObjectStoreOperation('delete', ['key' => new ObjectKey('tmp/video.mp4')]));
    }
}
