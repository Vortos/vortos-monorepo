<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contract;

/**
 * Produces event payloads to a named transport (broker topic/queue).
 *
 * Implementations are broker-specific (Kafka, InMemory, etc.).
 * Used by the outbox relay worker and by EventBus when outbox is disabled
 * for a producer.
 *
 * Payloads are plain POPOs — the producer treats them as opaque objects to
 * be serialized and shipped. All framework metadata (eventId, aggregate
 * identity, correlation) travels as headers; the producer never inspects
 * the payload's internals.
 *
 * Never call this directly from domain code — use EventBusInterface instead.
 */
interface ProducerInterface
{
    /**
     * Produce a single payload to the named transport.
     * Headers are merged with any default headers defined on the producer
     * definition. The caller (EventBus) supplies envelope-derived headers
     * such as event_id, payload_type, aggregate_id, occurred_at.
     */
    public function produce(string $transportName, object $payload, array $headers = []): void;

    /**
     * Produce multiple payloads to the named transport in a single batch.
     * More efficient than calling produce() in a loop for high-throughput
     * scenarios.
     *
     * @param object[] $payloads
     */
    public function produceBatch(string $transportName, array $payloads, array $headers = []): void;
}
