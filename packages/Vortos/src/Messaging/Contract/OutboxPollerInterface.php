<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contract;

/**
 * Polling and state management contract for the transactional outbox.
 *
 * Used by OutboxRelayWorker to fetch pending messages and transition
 * their state after relay attempts. Separate from OutboxInterface
 * which is the write-side contract used by EventBus.
 */
interface OutboxPollerInterface
{
    /**
     * Fetch a batch of pending outbox messages ready for relay.
     * Implementations must use SELECT FOR UPDATE SKIP LOCKED to support
     * multiple relay processes without contention.
     *
     * @return OutboxMessage[]
     */
    public function fetchPending(int $limit = 100): array;


    /**
     * Mark an outbox message as successfully published to the broker.
     */
    public function markPublished(string $outboxId):void;

    /**
     * Mark a message as failed. Increments attempt count and sets next_attempt_at
     * using exponential backoff. Marks as permanently failed after max attempts.
     */
    public function markFailed(string $outboxId, string $reason):void;

    /**
     * Fetch a batch of permanently failed outbox messages for manual replay.
     * Returns only messages with status = 'failed' (exhausted all attempts).
     *
     * @return OutboxMessage[]
     */
    public function fetchFailed(int $limit = 50, ?string $transport = null, ?string $eventClass = null, ?string $id = null, bool $orderDesc = false): array;

    /**
     * Reset a permanently failed outbox message back to pending so the relay
     * worker picks it up again. Resets attempt_count to 0 and clears next_attempt_at.
     */
    public function resetFailed(string $outboxId): void;
}