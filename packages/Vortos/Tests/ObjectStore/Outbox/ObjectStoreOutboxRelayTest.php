<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Outbox\ObjectOperationSerializer;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRelay;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;
use Vortos\ObjectStore\ValueObject\PromotionResult;
use Vortos\ObjectStore\ValueObject\StoredObject;

final class ObjectStoreOutboxRelayTest extends TestCase
{
    public function test_relay_processes_promote_operation_and_marks_done(): void
    {
        $serializer = new ObjectOperationSerializer();
        $payload = json_encode($serializer->toArray(new \Vortos\ObjectStore\Contract\ObjectStoreOperation('promote', [
            'request' => PromoteObjectRequest::fromKeys('tmp/video.mp4', 'registrations/video.mp4'),
        ])));

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([[
            'id' => 'outbox-1',
            'payload' => $payload,
            'attempt_count' => 0,
        ]]);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $executedSql = [];
        $connection->method('executeStatement')->willReturnCallback(function ($sql) use (&$executedSql) {
            $executedSql[] = $sql;
            return 1;
        });

        $direct = $this->createMock(DirectUploadManagerInterface::class);
        $direct->expects($this->once())
            ->method('promote')
            ->willReturn(new PromotionResult(
                new \Vortos\ObjectStore\ValueObject\ObjectKey('tmp/video.mp4'),
                new \Vortos\ObjectStore\ValueObject\ObjectKey('registrations/video.mp4'),
                new StoredObject(new \Vortos\ObjectStore\ValueObject\ObjectKey('registrations/video.mp4'), null, 0),
                true,
            ));

        $relay = new ObjectStoreOutboxRelay(
            $connection,
            $this->createMock(ObjectStoreInterface::class),
            $direct,
            $serializer,
            new NullLogger(),
            'object_store_outbox',
            10,
            3,
            5,
            300,
        );

        $this->assertSame(1, $relay->relay());
        $this->assertStringContainsString("status = 'done'", $executedSql[0]);
    }
}
