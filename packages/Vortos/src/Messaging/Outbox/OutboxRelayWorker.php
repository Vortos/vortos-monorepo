<?php

declare(strict_types=1);

namespace Vortos\Messaging\Outbox;

use DateTimeInterface;
use Vortos\Messaging\Contract\OutboxPollerInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Tracing\Contract\TracingInterface;
use Psr\Log\LoggerInterface;
use Vortos\Observability\Config\ObservabilityModule;

/**
 * Relays pending outbox messages to the broker.
 * 
 * Fetches a batch of pending messages from OutboxPoller, deserializes each,
 * produces to the configured transport via ProducerInterface, and marks as
 * published on success or failed on exception.
 * 
 * Each message is handled independently — one failure does not stop the batch.
 * The relay loop is run continuously by OutboxRelayRunner via OutboxRelayCommand.
 */
final class OutboxRelayWorker
{
    public function __construct(
        private OutboxPollerInterface $poller,
        private ProducerInterface $producer,
        private SerializerLocator $serializerLocator,
        private TransportRegistry $transportRegistry,
        private LoggerInterface $logger,
        private ?TracingInterface $tracer = null
    ){
    }

    public function relay(int $batchSize = 100):int
    {
        $messages = $this->poller->fetchPending($batchSize);
        $relayed = 0;

        foreach($messages as $outboxMessage){

            $span = $this->tracer?->startSpan('outbox.relay', [
                'outbox_id'    => $outboxMessage->id,
                'payload_type' => $outboxMessage->payloadType,
                'transport'    => $outboxMessage->transportName,
                'messaging.operation' => 'publish',
                'vortos.module' => ObservabilityModule::Messaging,
            ]);

            try {

                if (!class_exists($outboxMessage->payloadType)) {
                    throw new \UnexpectedValueException(
                        "Payload class '{$outboxMessage->payloadType}' does not exist."
                    );
                }

                if (!$this->transportRegistry->has($outboxMessage->transportName)) {
                    throw new \UnexpectedValueException(
                        "Unknown transport '{$outboxMessage->transportName}'."
                    );
                }

                $serializer = $this->serializerLocator->locate('json');
                $payload = $serializer->deserialize($outboxMessage->payload, $outboxMessage->payloadType);

                $headers = [
                    'event_id'          => $outboxMessage->eventId,
                    'payload_type'      => $outboxMessage->payloadType,
                    'aggregate_id'      => $outboxMessage->aggregateId,
                    'aggregate_type'    => $outboxMessage->aggregateType,
                    'aggregate_version' => (string) $outboxMessage->aggregateVersion,
                    'schema_version'    => (string) $outboxMessage->schemaVersion,
                    'occurred_at'       => $outboxMessage->occurredAt->format(DateTimeInterface::ATOM),
                    'correlation_id'    => $outboxMessage->correlationId ?? '',
                    'causation_id'      => $outboxMessage->causationId ?? '',
                    'trace_id'          => $outboxMessage->traceId ?? '',
                ];

                $this->producer->produce(
                    $outboxMessage->transportName,
                    $payload,
                    $headers,
                );

                $this->poller->markPublished($outboxMessage->id);
                $relayed++;

            } catch (\Throwable $e) {
                $this->logger->error('Outbox relay failed for message', [
                    'outbox_id'    => $outboxMessage->id,
                    'payload_type' => $outboxMessage->payloadType,
                    'transport'    => $outboxMessage->transportName,
                    'error'        => $e->getMessage(),
                ]);

                $this->poller->markFailed($outboxMessage->id, $e->getMessage());
            } finally {
                $span?->end();
            }
        }

        return $relayed;
    }
}
