<?php

declare(strict_types=1);

namespace Vortos\Messaging\Outbox;

use DateTimeImmutable;

/**
 * Represents a single outbox record fetched from the database.
 *
 * Produced by OutboxPoller::fetchPending() and consumed by OutboxRelayWorker.
 * Immutable — reflects the state of the database row at fetch time.
 * Use OutboxPoller::markPublished() or markFailed() to transition state.
 */
final readonly class OutboxMessage 
{
    public function __construct(
        public string $id,
        public string $transportName,
        public string $eventClass,
        public string $payload,
        public array $headers,

        /** One of: 'pending', 'published', 'failed' */
        public string $status, 
        public int $attemptCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $nextAttemptAt,
        public ?string $failureReason
    ){
    }

    /**
     * Construct from a raw DBAL database row.
     * Handles type coercion from database strings to PHP types.
     * Nullable columns (publishedAt, failureReason) default to null when absent.
     */
    public static function fromDatabaseRow(array $row):self
    {
        return new self(
            $row['id'],
            $row['transport_name'],
            $row['event_class'],
            $row['payload'],
            json_decode($row['headers'], true, 512),
            $row['status'],
            (int) $row['attempt_count'],
            new DateTimeImmutable($row['created_at']),
            isset($row['published_at']) ? new DateTimeImmutable($row['published_at'])  :  null,
            isset($row['next_attempt_at']) ? new DateTimeImmutable($row['next_attempt_at'])  :  null,
            $row['failure_reason'] ?? null
        );
    }
}
