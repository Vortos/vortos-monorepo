<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\InMemory\Runtime;

use DateTimeImmutable;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Messaging\ValueObject\ReceivedMessage;

/**
 * In-memory producer. Serializes payloads and enqueues them in InMemoryBroker.
 *
 * Used in tests to simulate event production without a real broker.
 * Messages are immediately available for InMemoryConsumer to process.
 */
final class InMemoryProducer implements ProducerInterface
{
    public function __construct(
        private InMemoryBroker $broker,
        private SerializerLocator $serializerLocator,
    ) {}

    public function produce(string $transportName, object $payload, array $headers = []): void
    {
        $serializer = $this->serializerLocator->locate('json');
        $serialized = $serializer->serialize($payload);

        $receivedMessage = new ReceivedMessage(
            id: bin2hex(random_bytes(8)),
            payload: $serialized,
            headers: $headers,
            transportName: $transportName,
            receivedAt: new DateTimeImmutable(),
            metadata: [],
        );

        $this->broker->enqueue($transportName, $receivedMessage);
    }

    public function produceBatch(string $transportName, array $payloads, array $headers = []): void
    {
        foreach ($payloads as $payload) {
            $this->produce($transportName, $payload, $headers);
        }
    }
}
