<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contract;

/**
 * Converts event payloads to and from wire format.
 *
 * Each serializer handles one format (e.g. 'json').
 * The SerializerLocator selects the correct implementation based on the
 * transport's configured format.
 *
 * Serializers operate on **payload objects only** — pure POPOs produced by
 * aggregates. They never see the EventEnvelope: framework metadata (eventId,
 * aggregateId, occurredAt, etc.) travels as outbox columns or transport
 * headers, not inside the serialized payload.
 *
 * Serializers are stateless and have no knowledge of envelopes or stamps.
 */
interface SerializerInterface
{
    /**
     * Serialize an event payload to a string for wire transmission.
     * The serializer treats the payload as opaque data — it walks public
     * readonly properties and produces the wire format.
     *
     * The payload class name is NOT included in the output by default.
     * Identifying the payload class on the consumer side is the responsibility
     * of the envelope/header layer, not the serializer.
     *
     * @throws \Vortos\Messaging\Serializer\Exception\SerializationException
     */
    public function serialize(object $payload): string;

    /**
     * Deserialize a raw string payload back into an instance of $payloadClass.
     * The consumer reads the payload class from the envelope header and passes
     * the FQCN here.
     *
     * @template T of object
     * @param class-string<T> $payloadClass
     * @return T
     *
     * @throws \Vortos\Messaging\Serializer\Exception\DeserializationException
     */
    public function deserialize(string $payload, string $payloadClass): object;

    /**
     * Returns true if this serializer handles the given format string.
     * Example: 'json', 'avro', 'protobuf'.
     */
    public function supports(string $format): bool;
}
