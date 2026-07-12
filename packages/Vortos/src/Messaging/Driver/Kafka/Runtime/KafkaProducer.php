<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Runtime;

use RdKafka\Producer;
use Throwable;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Driver\Kafka\Exception\ProducerException;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Kafka implementation of ProducerInterface using the RdKafka extension.
 *
 * produce() uses fire-and-poll — produces the message then does a non-blocking
 * poll to process any pending delivery callbacks.
 *
 * produceBatch() defers polling until all messages are enqueued, then flushes
 * once for efficiency. More throughput-friendly for high-volume scenarios.
 *
 * Headers are passed in by the caller (typically EventBus) and carry the full
 * envelope contract: event_id, payload_type, aggregate_id, aggregate_type,
 * aggregate_version, schema_version, occurred_at, correlation_id, etc. The
 * tracer adds W3C traceparent on top.
 */
final class KafkaProducer implements ProducerInterface
{
    public function __construct(
        private Producer $rdProducer,
        private SerializerLocator $serializerLocator,
        private TransportRegistry $transportRegistry,
        private ?TracingInterface $tracer,
        private string $defaultSerializer = 'json',
    ) {}

    public function produce(string $transportName, object $payload, array $headers = []): void
    {
        $this->enqueue($transportName, $payload, $headers);
        // Fire-and-poll: non-blocking, processes any pending delivery callbacks without waiting
        $this->rdProducer->poll(0);
    }

    public function produceBatch(string $transportName, array $payloads, array $headers = []): void
    {
        foreach ($payloads as $payload) {
            $this->enqueue($transportName, $payload, $headers);
        }

        $result    = $this->rdProducer->flush(10000);
        $remaining = $this->rdProducer->getOutQLen();

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR || $remaining > 0) {
            throw ProducerException::forBatchFlush($transportName, $result);
        }
    }

    private function enqueue(string $transportName, object $payload, array $headers = []): void
    {
        $payloadClass = $payload::class;

        try {
            $transportConfig = $this->transportRegistry->get($transportName);
            $format = $transportConfig['serializer'] ?? $this->defaultSerializer;
            $serializer = $this->serializerLocator->locate($format);
            $serialized = $serializer->serialize($payload);
            $topic = $this->rdProducer->newTopic($transportConfig['subscription']['topic']);

            $this->tracer?->injectHeaders($headers);

            // Partition key = aggregate_id: co-partitions all events for one aggregate so the
            // broker preserves per-aggregate order (and lets keyed compaction work). Empty
            // aggregate_id → null key → round-robin, unchanged from before.
            $partitionKey = ($headers['aggregate_id'] ?? '') !== '' ? (string) $headers['aggregate_id'] : null;

            $topic->producev(RD_KAFKA_PARTITION_UA, 0, $serialized, $partitionKey, $headers);
        } catch (\RdKafka\Exception | Throwable $e) {
            throw ProducerException::forTransport($transportName, $payloadClass, $e);
        }
    }
}
