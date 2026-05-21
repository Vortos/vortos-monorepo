<?php

declare(strict_types=1);

namespace Vortos\Messaging\Bus;

use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope as MessengerEnvelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\UuidV7;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Bus\Stamp\CorrelationIdStamp;
use Vortos\Messaging\Bus\Stamp\EventIdStamp;
use Vortos\Messaging\Bus\Stamp\TimestampStamp;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Contract\OutboxInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Hook\HookRunner;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Registry\ProducerRegistry;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Internal in-process event bus. Wraps Symfony Messenger for handler dispatch
 * and coordinates outbox/direct broker production for external delivery.
 *
 * Accepts EventEnvelopes (produced by AggregateRoot::recordEvent). Enriches
 * Metadata at dispatch (correlation from tracer if absent), builds wire
 * headers from the envelope, runs hooks, and routes:
 *
 *   1. In-process handlers via Symfony Messenger (payload as the message,
 *      stamps carrying envelope metadata).
 *   2. Outbox.store(envelope, transport) — if a producer is registered with
 *      outbox enabled (default).
 *   3. Producer.produce(transport, payload, headers) — if outbox disabled.
 *
 * The outbox path requires an active database transaction. Callers are
 * responsible for transaction boundaries when dispatching outside of a
 * TransactionalMiddleware-wrapped handler.
 */
final class EventBus implements EventBusInterface
{
    /** @var array<class-string, bool> payloadType → true for types that have at least one in-process consumer */
    private array $inProcessPayloadTypes;

    public function __construct(
        private MessageBusInterface $bus,
        private OutboxInterface $outbox,
        private ProducerInterface $producer,
        private HandlerRegistry $handlerRegistry,
        private ProducerRegistry $producerRegistry,
        private array $eventProducerMap,
        private HookRunner $hookRunner,
        private LoggerInterface $logger,
        private ?TracingInterface $tracer,
        private ConsumerRegistry $consumerRegistry,
    ) {
        $this->inProcessPayloadTypes = $this->buildInProcessPayloadTypeIndex();
    }

    public function dispatch(EventEnvelope $envelope): void
    {
        $envelope = $this->enrichMetadata($envelope);
        $headers = $this->headersFromEnvelope($envelope);

        $throwable = null;
        try {
            $this->hookRunner->runBeforeDispatch($envelope);

            $payloadType = $envelope->payloadType;
            $hasHandlers = isset($this->inProcessPayloadTypes[$payloadType]);

            if ($hasHandlers) {
                $messengerEnvelope = new MessengerEnvelope($envelope->payload, [
                    new EventIdStamp($envelope->eventId),
                    new TimestampStamp($envelope->occurredAt),
                    new CorrelationIdStamp($envelope->metadata->correlationId ?? ''),
                ]);
                $this->bus->dispatch($messengerEnvelope);
            }

            $producerName = $this->eventProducerMap[$payloadType] ?? null;

            if ($producerName !== null) {
                $producerConfig = $this->producerRegistry->get($producerName);

                $this->hookRunner->runPreSend($envelope, $headers);

                $outboxEnabled = $producerConfig['outbox']['enabled'] ?? true;
                $transport = $producerConfig['transport'] ?? '';

                if ($outboxEnabled) {
                    $this->outbox->store($envelope, $transport);
                } else {
                    $this->producer->produce($transport, $envelope->payload, $headers);
                }
            }

            if (!$hasHandlers && $producerName === null) {
                $this->logger->warning(
                    'Event dispatched but no handlers or producer registered',
                    ['payload_type' => $payloadType],
                );
            }
        } catch (\Throwable $e) {
            $throwable = $e;
            throw $e;
        } finally {
            $this->hookRunner->runAfterDispatch($envelope, $throwable);
        }
    }

    public function dispatchBatch(EventEnvelope ...$envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $this->dispatch($envelope);
        }
    }

    /**
     * Enrich envelope Metadata with a correlation id from the active tracer
     * (or a freshly generated UUIDv7) when one is not already set. Other
     * Metadata fields are left untouched — they are set by hooks (PreSend)
     * or higher-level orchestrators.
     */
    private function enrichMetadata(EventEnvelope $envelope): EventEnvelope
    {
        if ($envelope->metadata->correlationId !== null) {
            return $envelope;
        }

        $correlationId = $this->tracer?->currentCorrelationId() ?? new UuidV7()->toRfc4122();

        return $envelope->withMetadata(new Metadata(
            correlationId: $correlationId,
            causationId:   $envelope->metadata->causationId,
            traceId:       $envelope->metadata->traceId,
            tenantId:      $envelope->metadata->tenantId,
            userId:        $envelope->metadata->userId,
            custom:        $envelope->metadata->custom,
        ));
    }

    /**
     * Translate envelope fields into wire headers used by producers and
     * inspected by hooks. PreSend hooks may mutate this array before send.
     *
     * @return array<string, string>
     */
    private function headersFromEnvelope(EventEnvelope $envelope): array
    {
        return [
            'event_id'          => $envelope->eventId,
            'payload_type'      => $envelope->payloadType,
            'aggregate_id'      => $envelope->aggregateId,
            'aggregate_type'    => $envelope->aggregateType,
            'aggregate_version' => (string) $envelope->aggregateVersion,
            'schema_version'    => (string) $envelope->schemaVersion,
            'occurred_at'       => $envelope->occurredAt->format(DateTimeInterface::ATOM),
            'correlation_id'    => $envelope->metadata->correlationId ?? '',
            'causation_id'      => $envelope->metadata->causationId ?? '',
            'trace_id'          => $envelope->metadata->traceId ?? '',
        ];
    }

    /**
     * Builds an index of payload types that have at least one in-process
     * consumer handler. O(consumers × payload_types) at startup, O(1) at dispatch.
     */
    private function buildInProcessPayloadTypeIndex(): array
    {
        $index = [];

        foreach ($this->handlerRegistry->allConsumers() as $consumerName) {
            try {
                $consumerConfig = $this->consumerRegistry->get($consumerName);
            } catch (\Throwable) {
                continue;
            }

            if (!($consumerConfig['inProcess'] ?? false)) {
                continue;
            }

            foreach (array_keys($this->handlerRegistry->allForConsumer($consumerName)) as $payloadType) {
                $index[$payloadType] = true;
            }
        }

        return $index;
    }
}
