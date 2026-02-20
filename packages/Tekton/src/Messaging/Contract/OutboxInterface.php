<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Contract;

/**
 * Transactional outbox contract.
 *
 * Guarantees at-least-once delivery by writing events to a database table
 * within the same transaction as the domain change. The OutboxRelayWorker
 * then reads pending messages and produces them to the broker asynchronously.
 * Implementations must NOT open their own transaction — the caller owns it.
 */
interface OutboxInterface
{
    /**
     * Store an event in the outbox table within the caller's active transaction.
     * Never call this outside a transaction boundary.
     */ 
    public function store(DomainEventInterface $event, string $transportName, array $headers = []):void ;

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
     * Mark an outbox message as failed with a reason.
     * Implementations should increment attempt count and set next retry time.
     */
    public function markFailed(string $outboxId, string $reason):void;
}