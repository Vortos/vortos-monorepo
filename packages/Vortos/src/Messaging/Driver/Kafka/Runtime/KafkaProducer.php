<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Runtime;

use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Driver\Kafka\Exception\ProducerException;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Tracing\Contract\TracingInterface;
use RdKafka\Producer;
use Throwable;
use Vortos\Domain\Event\DomainEventInterface;

/**
 * Kafka implementation of ProducerInterface using the RdKafka extension.
 * 
 * produce() uses fire-and-poll — produces the message then does a non-blocking
 * poll to process any pending delivery callbacks.
 * 
 * produceBatch() defers polling until all messages are enqueued, then flushes
 * once for efficiency. More throughput-friendly for high-volume scenarios.
 * 
 * Never inject this directly into domain code — use ProducerInterface or
 * dispatch through EventBus instead.
 */
final class KafkaProducer implements ProducerInterface
{
    public function __construct(
        private Producer $rdProducer,
        private SerializerLocator $serializerLocator,
        private TransportRegistry $transportRegistry,
        private TracingInterface $tracer,
        private string $defaultSerializer = 'json'
    ) {}

    public function produce(string $transportName, DomainEventInterface $event, array $headers = []): void
    {
        $this->enqueue($transportName, $event, $headers);
      
        $result = $this->rdProducer->flush(10000);
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw ProducerException::forTransport(
                $transportName,
                get_class($event),
                new \RuntimeException('Failed to flush message to Kafka, error code: ' . $result)
            );
        }
    }


    public function produceBatch(string $transportName, array $events, array $headers = []): void
    {
        foreach ($events as $event) {
            $this->enqueue($transportName, $event, $headers);
        }

        $result = $this->rdProducer->flush(10000);

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw ProducerException::forBatchFlush(
                $transportName,
                $result
            );
        }
    }

    private function enqueue(string $transportName, DomainEventInterface $event, array $headers = []): void
    {
        $eventClass = get_class($event);

        try {
            $transportConfig = $this->transportRegistry->get($transportName);

            $format = $transportConfig['serializer'] ?? $this->defaultSerializer;

            $serializer = $this->serializerLocator->locate($format);

            $payload = $serializer->serialize($event);

            $topic = $this->rdProducer->newTopic($transportConfig['subscription']['topic']);

            $finalHeaders = array_merge(
                ['event_class' => $eventClass],
                $headers
            );

            $this->tracer->injectHeaders($finalHeaders);

            $topic->producev(RD_KAFKA_PARTITION_UA, 0, $payload, null, $finalHeaders);
        } catch (\RdKafka\Exception | Throwable $e) {

            throw ProducerException::forTransport(
                $transportName,
                $eventClass,
                $e
            );
        }
    }
}
