<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Outbox;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Exception\OutboxSerializationException;
use Vortos\ObjectStore\Outbox\ObjectOperationSerializer;
use Vortos\ObjectStore\ValueObject\ObjectBody;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PromoteObjectRequest;

final class ObjectOperationSerializerTest extends TestCase
{
    public function test_serializes_and_restores_promote_operation(): void
    {
        $serializer = new ObjectOperationSerializer();
        $payload = $serializer->toArray(new ObjectStoreOperation('promote', [
            'request' => PromoteObjectRequest::fromKeys('tmp/video.mp4', 'registrations/video.mp4'),
        ]));

        $operation = $serializer->fromArray($payload);

        $this->assertSame('promote', $operation->name());
        $this->assertSame('tmp/video.mp4', $operation->context()['request']->temporaryKey()->value());
        $this->assertSame('registrations/video.mp4', $operation->context()['request']->permanentKey()->value());
    }

    public function test_rejects_stream_put_payloads(): void
    {
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, 'abc');
        rewind($stream);

        $this->expectException(OutboxSerializationException::class);

        (new ObjectOperationSerializer())->toArray(new ObjectStoreOperation('put', [
            'key' => new ObjectKey('small.txt'),
            'body' => ObjectBody::from($stream),
        ]));
    }

    public function test_rejects_large_inline_put_payloads(): void
    {
        $this->expectException(OutboxSerializationException::class);

        (new ObjectOperationSerializer(2))->toArray(new ObjectStoreOperation('put', [
            'key' => new ObjectKey('small.txt'),
            'body' => ObjectBody::from('abc'),
        ]));
    }
}
