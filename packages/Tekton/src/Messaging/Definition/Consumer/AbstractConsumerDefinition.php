<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Definition\Consumer;

/**
 * Base class for consumer definitions.
 *
 * A consumer definition is a value object that describes how a worker fleet
 * processes messages from a transport — parallelism, batch size, retry policy,
 * and dead-letter routing. It holds no runtime state and performs no I/O.
 *
 * Every broker-specific consumer (Kafka, RabbitMQ) extends this.
 * Users build these via fluent methods inside a MessagingConfig class.
 */
abstract class AbstractConsumerDefinition
{
    protected string $transportName;
    protected int $parallelism = 1;
    protected int $batchSize = 1;
    protected array $retryPolicy = [];
    protected string $dlqTransport = '';

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
     * How many messages one worker process handles concurrently.
     * For CPU-bound handlers keep this at 1. For I/O-bound handlers increase carefully.
     * This is message-level parallelism within a single process, not process count.
     */
    public function parallelism(int $count= 1):static
    {
        $this->parallelism = $count;
        return $this;
    }

    /**
     * How many messages are fetched and processed together before committing offset.
     * Higher values improve throughput but increase reprocessing window on failure.
     */
    public function batchSize(int $size= 1):static
    {
        $this->batchSize = $size;
        return $this;
    }

    /**
     * Retry policy configuration array.
     * Use RetryPolicy value object keys: attempts, backoff, initialDelayMs, maxDelayMs.
     * Example: ['attempts' => 3, 'backoff' => 'exponential', 'initialDelayMs' => 500]
     */
    public function retry(array $policy):static
    {
        $this->retryPolicy = $policy;
        return $this;
    }

    /**
     * Name of the transport to use as the dead-letter destination.
     * Messages that exhaust all retry attempts are routed here.
     * Must reference a registered transport name.
     */
    public function dlq(string $dlqTransportName):static
    {
        $this->dlqTransport = $dlqTransportName;
        return $this;
    }

    /** Returns normalized configuration array consumed by the runtime consumer factory. */
    abstract public function toArray(): array;
}