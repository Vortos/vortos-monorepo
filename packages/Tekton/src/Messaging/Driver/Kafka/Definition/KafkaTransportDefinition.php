<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Driver\Kafka\Definition;

use Fortizan\Tekton\Messaging\Definition\Transport\AbstractTransportDefinition;
use Fortizan\Tekton\Messaging\Driver\Kafka\ValueObject\SaslConfig;
use Fortizan\Tekton\Messaging\Driver\Kafka\ValueObject\SslConfig;

/**
 * Kafka-specific transport definition.
 *
 * Extends the base transport with Kafka concepts: topic name, partition count,
 * replication factor, and optional SASL/SSL security configuration.
 * Built fluently inside a MessagingConfig class.
 *
 * Example:
 *   KafkaTransportDefinition::create('orders.placed')
 *       ->dsn('kafka://broker:9092')
 *       ->topic('orders.placed')
 *       ->partitions(12)
 *       ->replicationFactor(3)
 *       ->security(SaslConfig::scramSha256('user', 'pass'));
 */
final class KafkaTransportDefinition extends AbstractTransportDefinition
{
    private string $topic = '';
    private int $partitions = 1;
    private int $replicationFactor = 1;
    private ?SaslConfig $sasl = null;
    private ?SslConfig $ssl = null;

    /** The Kafka topic name this transport reads from and writes to. */
    public function topic(string $topic): static
    {
        $this->topic = $topic;
        return $this;
    }

    /**
     * Number of partitions for this topic.
     * Controls maximum consumer parallelism. 12 is a common starting point for high-throughput topics.
     * Only used during topic provisioning — has no effect on existing topics.
     */
    public function partitions(int $count): static
    {
        $this->partitions = $count;
        return $this;
    }

    /**
     * Replication factor for this topic.
     * Must be <= number of brokers in the cluster. 3 is the standard for production.
     * Only used during topic provisioning — has no effect on existing topics.
     */
    public function replicationFactor(int $count): static
    {
        $this->replicationFactor = $count;
        return $this;
    }

    /**
     * SASL authentication configuration.
     * Use SaslConfig::plain(), SaslConfig::scramSha256(), or SaslConfig::scramSha512().
     * Always pair with ssl() in production environments.
     */
    public function security(SaslConfig $sasl): static
    {
        $this->sasl = $sasl;
        return $this;
    }

    /** SSL/TLS configuration for encrypted broker connections. */
    public function ssl(SslConfig $ssl): static
    {
        $this->ssl = $ssl;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'driver' => 'kafka',
            'name' => $this->name,
            'dsn' => $this->dsn,
            'subscription' => [
                'topic' => $this->topic,
            ],
            'provisioning' => [
                'partitions' => $this->partitions,
                'replication' => $this->replicationFactor,
            ],
            'security' => array_filter([
                'sasl' => $this->sasl?->toArray(),
                'ssl' => $this->ssl?->toArray(),
            ]),
            'serializer' => $this->serializer,
        ];
    }
}
