<?php

declare(strict_types=1);

namespace Vortos\Messaging\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\UuidV7;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Contract\OutboxInterface;
use Vortos\Messaging\Outbox\Exception\OutboxWriteException;
use Vortos\Messaging\Serializer\SerializerLocator;

/**
 * Writes event envelopes to the outbox table within the caller's active
 * database transaction.
 *
 * Never starts its own transaction. The caller — typically a command handler
 * wrapped by TransactionalMiddleware — owns the transaction boundary. The
 * outbox row and the domain changes commit atomically or roll back together.
 *
 * Envelope fields are persisted as first-class columns so the OutboxPoller
 * and downstream tooling can query/filter without parsing JSON. The payload
 * itself is serialized via the JSON serializer; only domain data lives there.
 */
final class OutboxWriter implements OutboxInterface
{
    public function __construct(
        private Connection $connection,
        private SerializerLocator $serializerLocator,
        private string $table = 'vortos_outbox',
    ) {}

    public function store(EventEnvelope $envelope, string $transportName): void
    {
        try {
            $id = new UuidV7()->toRfc4122();
            $serializer = $this->serializerLocator->locate('json');
            $payload = $serializer->serialize($envelope->payload);

            $metadataExtra = array_filter(
                [
                    'tenantId' => $envelope->metadata->tenantId,
                    'userId'   => $envelope->metadata->userId,
                ],
                fn($v) => $v !== null,
            );
            $metadataExtra = array_merge($metadataExtra, $envelope->metadata->custom);
            $metadataJson = $metadataExtra === []
                ? null
                : json_encode($metadataExtra, JSON_THROW_ON_ERROR);

            $now = new DateTimeImmutable();

            $this->connection->insert($this->table, [
                'id'                => $id,
                'transport_name'    => $transportName,
                'event_id'          => $envelope->eventId,
                'aggregate_id'      => $envelope->aggregateId,
                'aggregate_type'    => $envelope->aggregateType,
                'aggregate_version' => $envelope->aggregateVersion,
                'payload_type'      => $envelope->payloadType,
                'schema_version'    => $envelope->schemaVersion,
                'occurred_at'       => $envelope->occurredAt->format('Y-m-d H:i:s'),
                'correlation_id'    => $envelope->metadata->correlationId,
                'causation_id'      => $envelope->metadata->causationId,
                'trace_id'          => $envelope->metadata->traceId,
                'metadata'          => $metadataJson,
                'payload'           => $payload,
                'status'            => 'pending',
                'attempt_count'     => 0,
                'created_at'        => $now->format('Y-m-d H:i:s'),
                'published_at'      => null,
                'next_attempt_at'   => null,
                'failure_reason'    => null,
            ]);
        } catch (\Throwable $e) {
            throw OutboxWriteException::forEvent(
                $envelope->payloadType,
                $transportName,
                $e,
            );
        }
    }
}
