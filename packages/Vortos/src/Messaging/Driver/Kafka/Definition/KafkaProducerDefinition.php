<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Definition;

use Vortos\Messaging\Definition\Producer\AbstractProducerDefinition;

/**
 * Kafka-specific producer definition.
 *
 * Describes how events are produced to a Kafka topic — which transport to use,
 * whether the outbox pattern is enabled, compression settings, and batching behavior.
 * Built fluently inside a MessagingConfig class via a #[RegisterProducer] method.
 *
 * Example:
 *   KafkaProducerDefinition::create('orders.placed')
 *       ->transport('orders.placed')
 *       ->outbox(true)
 *       ->compression('snappy')
 *       ->linger(10);
 */
final class KafkaProducerDefinition extends AbstractProducerDefinition
{
    private bool $compressionEnabled = false;

    /** One of: 'snappy', 'lz4', 'gzip', 'zstd' */
    private string $compressionType = 'snappy';
    private int $lingerMs = 5;
    private int $maxBatchBytes = 1048576;

    /**
     * The name of the registered transport this producer sends events to.
     * Must match a transport name registered via #[RegisterTransport].
     * Validated by the compiler pass at container compile time.
     */
    public function transport(string $name):static
    {
        $this->transportName = $name;
        return $this;
    }

    public function compression(string $type):static
    {
        $this->compressionType = $type;
        return $this;
    }

    public function linger(int $ms):static
    {
        $this->lingerMs = $ms;
        return $this;
    }

    public function maxBatchBytes(int $bytes):static
    {
        $this->maxBatchBytes = $bytes;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'transport' => $this->transportName,
            'publishes' => $this->publishedEvents,
            'compression' => [
                'enabled' => $this->compressionEnabled,
                'type' => $this->compressionType,
            ],
            'lingerMs' => $this->lingerMs,
            'maxBatchBytes' => $this->maxBatchBytes,
            'outbox' => [
                'enabled' => $this->outboxEnabled,
            ],
            'headers' => $this->headers,
        ];
    }
}