<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Definition;

use Vortos\Foundation\Config\Env;
use Vortos\Messaging\Definition\Transport\AbstractTransportDefinition;
use Vortos\Messaging\Driver\Kafka\ValueObject\SaslConfig;
use Vortos\Messaging\Driver\Kafka\ValueObject\SslConfig;

/**
 * Kafka-specific transport definition.
 *
 * Extends the base transport with Kafka concepts: topic name, partition count,
 * replication factor, and optional SASL/SSL security configuration.
 * Built fluently inside a MessagingConfig class.
 *
 * Every setting accepts either a literal or an Env reference — Env values
 * resolve from the environment at runtime via the container (see Env).
 *
 * Example:
 *   KafkaTransportDefinition::create('orders.placed')
 *       ->dsn(Env::string('KAFKA_BROKERS'))
 *       ->topic('orders.placed')
 *       ->partitions(Env::int('KAFKA_PARTITIONS', default: 12))
 *       ->replicationFactor(Env::int('KAFKA_REPLICATION_FACTOR', default: 3))
 *       ->security(SaslConfig::scramSha256(Env::string('KAFKA_SASL_USER'), Env::string('KAFKA_SASL_PASS')));
 */
final class KafkaTransportDefinition extends AbstractTransportDefinition
{
    private string|Env $topic = '';
    private int|Env $partitions = 1;
    private int|Env $replicationFactor = 1;
    private ?SaslConfig $sasl = null;
    private ?SslConfig $ssl = null;

    /** The Kafka topic name this transport reads from and writes to. */
    public function topic(string|Env $topic): static
    {
        $this->topic = $topic;
        return $this;
    }

    /**
     * Number of partitions for this topic.
     * Controls maximum consumer parallelism. 12 is a common starting point for high-throughput topics.
     * Only used during topic provisioning — has no effect on existing topics.
     */
    public function partitions(int|Env $count): static
    {
        $this->partitions = $count;
        return $this;
    }

    /**
     * Replication factor for this topic.
     * Must be <= number of brokers in the cluster. 3 is the standard for production.
     * Only used during topic provisioning — has no effect on existing topics.
     */
    public function replicationFactor(int|Env $count): static
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
