<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Outbox;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectOutboxWriterInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Driver\Null\NullObjectStore;
use Vortos\ObjectStore\Outbox\TransactionalOutboxObjectStore;

final class TransactionalOutboxObjectStoreTest extends TestCase
{
    public function test_delete_queues_operation_instead_of_deleting_immediately(): void
    {
        $captured = null;
        $writer = new class($captured) implements ObjectOutboxWriterInterface {
            public function __construct(private mixed &$captured) {}
            public function queue(ObjectStoreOperation $operation, ?string $domainEventId = null): void
            {
                $this->captured = $operation;
            }
        };

        $store = new TransactionalOutboxObjectStore($writer, new NullObjectStore());
        $result = $store->delete('tmp/video.mp4');

        $this->assertFalse($result->deleted());
        $this->assertSame('delete', $captured->name());
        $this->assertSame('tmp/video.mp4', $captured->context()['key']->value());
    }
}
