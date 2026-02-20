<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Contract;

/**
 * Converts domain events to and from wire format.
 *
 * Each serializer handles one format (e.g. 'json').
 * The SerializerLocator selects the correct implementation
 * based on the transport's configured format.
 * Serializers are stateless and have no knowledge of envelopes or stamps.
 */
interface SerializerInterface
{
    /**
     * Serialize a domain event to a string payload for wire transmission.
     * Must include enough information for deserialization (e.g. event class name).
     *
     * @throws SerializationException
     */
    public function serialize(DomainEventInterface $event): string;

    /**
     * Deserialize a raw string payload back into a domain event object.
     *
     * @throws DeserializationException
     */
    public function deserialize(string $payload, string $eventClass): DomainEventInterface;

    /**
     * Returns true if this serializer handles the given format string.
     * Example: 'json', 'avro', 'protobuf'
     */
    public function supports(string $format):bool;
}