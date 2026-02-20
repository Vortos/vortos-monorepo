<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Contract;

/**
 * Produces domain events to a named transport (broker topic/queue).
 *
 * Implementations are broker-specific (Kafka, InMemory, etc.).
 * This interface is used by the outbox relay and direct producers.
 * Never call this directly from domain code — use EventBusInterface instead.
 */
interface ProducerInterface
{
    /**
     * Produce a single event to the named transport.
     * Headers are merged with any default headers defined on the producer definition.
     */
    public function produce(string $transportName, DomainEventInterface $event, array $headers = []): void;

    /**
     * Produce multiple events to the named transport in a single batch.
     * More efficient than calling produce() in a loop for high-throughput scenarios.
     *
     * @param DomainEventInterface[] $events
     */
    public function produceBatch(string $transportName, array $events, array $headers = []): void;
}
