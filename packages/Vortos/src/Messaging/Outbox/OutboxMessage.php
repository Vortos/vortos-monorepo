<?php

declare(strict_types=1);

namespace Vortos\Messaging\Outbox;

use DateTimeImmutable;

/**
 * Represents a single outbox row fetched from the database.
 *
 * Produced by OutboxPoller::fetchPending() and consumed by OutboxRelayWorker.
 * Immutable — reflects the state of the database row at fetch time.
 * Use OutboxPoller::markPublished() or markFailed() to transition state.
 *
 * Mirrors the messaging_outbox schema: envelope fields are first-class
 * columns (eventId, aggregateId, aggregateType, aggregateVersion, payloadType,
 * schemaVersion, occurredAt, correlation/causation/trace ids) rather than
 * stuffed into a generic `headers` JSON blob.
 */
final readonly class OutboxMessage
{
    public function __construct(
        public string $id,
        public string $transportName,
        public string $eventId,
        public string $aggregateId,
        public string $aggregateType,
        public int $aggregateVersion,
        public string $payloadType,
        public int $schemaVersion,
        public DateTimeImmutable $occurredAt,
        public ?string $correlationId,
        public ?string $causationId,
        public ?string $traceId,
        /** Decoded jsonb metadata (tenant, user, custom). Empty array if column is null. */
        public array $metadata,
        public string $payload,
        /** One of: 'pending', 'published', 'failed' */
        public string $status,
        public int $attemptCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $nextAttemptAt,
        public ?string $failureReason,
    ) {}

    /**
     * Construct from a raw DBAL database row.
     * Handles type coercion from database strings to PHP types.
     */
    public static function fromDatabaseRow(array $row): self
    {
        $metadataRaw = $row['metadata'] ?? null;
        $metadata = is_string($metadataRaw) && $metadataRaw !== ''
            ? (json_decode($metadataRaw, true, 512) ?? [])
            : (is_array($metadataRaw) ? $metadataRaw : []);

        return new self(
            id:                $row['id'],
            transportName:     $row['transport_name'],
            eventId:           $row['event_id'],
            aggregateId:       $row['aggregate_id'],
            aggregateType:     $row['aggregate_type'],
            aggregateVersion:  (int) $row['aggregate_version'],
            payloadType:       $row['payload_type'],
            schemaVersion:     (int) $row['schema_version'],
            occurredAt:        new DateTimeImmutable($row['occurred_at']),
            correlationId:     $row['correlation_id'] ?? null,
            causationId:       $row['causation_id'] ?? null,
            traceId:           $row['trace_id'] ?? null,
            metadata:          $metadata,
            payload:           $row['payload'],
            status:            $row['status'],
            attemptCount:      (int) $row['attempt_count'],
            createdAt:         new DateTimeImmutable($row['created_at']),
            publishedAt:       isset($row['published_at']) ? new DateTimeImmutable($row['published_at']) : null,
            nextAttemptAt:     isset($row['next_attempt_at']) ? new DateTimeImmutable($row['next_attempt_at']) : null,
            failureReason:     $row['failure_reason'] ?? null,
        );
    }
}
