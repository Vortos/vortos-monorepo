<?php
declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\DomainEvent;
use Vortos\Messaging\Serializer\JsonSerializer;
use Vortos\Messaging\Serializer\Exception\DeserializationException;

final readonly class TestSerializerEvent extends DomainEvent
{
    public function __construct(
        string $aggregateId = 'agg-1',
        public string $name = 'test'
    ) {
        parent::__construct($aggregateId);
    }
}

final class JsonSerializerTest extends TestCase
{
    private JsonSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonSerializer();
    }

    public function test_serialize_produces_json(): void
    {
        $event = new TestSerializerEvent('agg-1', 'hello');
        $serialized = $this->serializer->serialize($event);
        $this->assertIsString($serialized);
        $this->assertJson($serialized);
    }

    public function test_deserialize_throws_on_unknown_class(): void
    {
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize('{"_class":"NonExistent\\\\Class"}', 'unknown');
    }
}
