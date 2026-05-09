<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Definition;

use Vortos\Messaging\Definition\Consumer\AbstractConsumerDefinition;

/**
 * Kafka-specific consumer definition.
 *
 * Describes how a worker fleet consumes from a Kafka topic — consumer group,
 * parallelism, batch size, retry policy, DLQ routing, and Kafka-specific
 * poll/fetch tuning parameters.
 * Built fluently inside a MessagingConfig class via a #[RegisterConsumer] method.
 *
 * Example:
 *   KafkaConsumerDefinition::create('orders.placed')
 *       ->groupId('order-service')
 *       ->parallelism(4)
 *       ->batchSize(100)
 *       ->retry(RetryPolicy::exponential(attempts: 3, initialDelayMs: 500))
 *       ->dlq('orders.placed.dlq')
 *       ->maxPollInterval(60000);
 */
final class KafkaConsumerDefinition extends AbstractConsumerDefinition
{
    private string $groupId = '';
    private int $sessionTimeoutMs = 30000;
    private int $maxPollIntervalMs = 300000;

    /** One of: 'earliest', 'latest' */
    private string $autoOffsetResetPolicy = 'earliest';
    private int $fetchMinBytes = 1;
    private int $fetchMaxWaitMs = 500;
    private bool $inProcess = false;

    /**
     * Kafka consumer group ID. All worker processes running this consumer
     * must share the same group ID for Kafka to distribute partitions among them.
     */
    public function groupId(string $id):static
    {
        $this->groupId = $id;
        return $this;
    }

    public function sessionTimeout(int $ms):static
    {
        $this->sessionTimeoutMs = $ms;
        return $this;
    }

    /**
     * max time between polls before consumer is considered failed. 
     * Must be greater than your longest handler execution time
     */
    public function maxPollInterval(int $ms):static
    {
        $this->maxPollIntervalMs = $ms;
        return $this;
    }

    /**
     * Offset reset policy when no committed offset exists for this group.
     * 'earliest' processes all messages from the beginning of the topic.
     * 'latest' skips existing messages and only processes new ones.
     * Default is 'earliest' — safer for event-driven systems.
     */
    public function offsetReset(string $policy):static
    {
        $this->autoOffsetResetPolicy = $policy;
        return $this;
    }

    /**
     * Kafka fetch tuning. Increase minBytes for higher throughput at cost of latency.
     * maxWaitMs is the broker's maximum wait time before returning a fetch response.
     */
    public function fetchConfig(int $minBytes, int $maxWaitMs):static
    {
        $this->fetchMinBytes = $minBytes;
        $this->fetchMaxWaitMs = $maxWaitMs;
        return $this;
    }

    /**
     * Mark this consumer as in-process only — no Kafka transport used.
     * When true, EventBus dispatches events to handlers directly in-process
     * via Symfony Messenger instead of routing through Kafka.
     * Use for lightweight internal events that do not need external delivery.
     */
    public function inProcess(bool $inProcess = true): static
    {
        $this->inProcess = $inProcess;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'transport' => $this->transportName,
            'groupId' => $this->groupId,
            'parallelism' => $this->parallelism,
            'batchSize' => $this->batchSize,
            'retry' => $this->retryPolicy?->toArray() ?? [],
            'dlq' => $this->dlqTransport,
            'idempotencyTtl' => $this->idempotencyTtl,
            'inProcess' => $this->inProcess,
            'kafka' => [
                'asyncCommit' => $this->asyncCommit,
                'sessionTimeoutMs' => $this->sessionTimeoutMs,
                'maxPollIntervalMs' => $this->maxPollIntervalMs,
                'autoOffsetResetPolicy' => $this->autoOffsetResetPolicy,
                'fetchMinBytes' => $this->fetchMinBytes,
                'fetchMaxWaitMs' => $this->fetchMaxWaitMs,
            ],
        ];
    }
}