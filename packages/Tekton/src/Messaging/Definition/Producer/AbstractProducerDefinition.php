<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Definition\Producer;

/**
 * Base class for producer definitions.
 *
 * A producer definition is a value object that describes how events are sent
 * to a transport — whether the outbox pattern is used, the outbox table name,
 * and any default headers to attach to every message.
 *
 * Every broker-specific producer (Kafka, RabbitMQ) extends this.
 * Users build these via fluent methods inside a MessagingConfig class.
 */
abstract class AbstractProducerDefinition
{
    protected string $transportName;
    protected bool $outboxEnabled = true;
    protected string $outboxTable = 'outbox';
    protected array $headers = [];

    protected function __construct(string $transportName)
    {
        $this->transportName = $transportName;
    }

    /** Named constructor. Always use this instead of new. */
    public static function create(string $transportName):static
    {
        return new static($transportName);
    }

    /**
     * Configure the transactional outbox for this producer.
     * When enabled (default), events are written to the outbox table within the
     * domain transaction and relayed to the broker asynchronously by the OutboxRelayWorker.
     * Disable only when you explicitly need synchronous direct-to-broker production.
     */
    public function outbox(bool $enabled = true, string $table = 'outbox'): static
    {
        $this->outboxEnabled = $enabled;
        $this->outboxTable = $table;
        return $this;
    }

    /**
     * Default headers attached to every message produced by this producer.
     * Merged with headers passed at call time. Call-time headers take precedence.
     */
    public function headers(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    /** Returns normalized configuration array consumed by the runtime producer factory. */
    abstract public function toArray(): array;
}
