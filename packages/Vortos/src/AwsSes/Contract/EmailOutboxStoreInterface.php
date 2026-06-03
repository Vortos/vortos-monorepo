<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

use Vortos\AwsSes\Outbox\OutboxEntry;

/**
 * Read model for the SES transactional outbox.
 *
 * Callers that need to retrieve the real AWS MessageId after deferred delivery
 * can use this store to look up outbox rows by their stable outbox UUID or by
 * the AWS MessageId written back by the relay worker.
 *
 * ## Typical usage after sending via the outbox
 *
 *   $sent    = $mailer->send($email);        // returns immediately; delivery is deferred
 *   $outboxId = $sent->messageId();          // UUID v7 — store this in your domain model
 *
 *   // Later, after the relay worker has processed the row:
 *   $entry = $store->findById($outboxId);
 *   if ($entry?->isDelivered()) {
 *       $awsMessageId = $entry->awsMessageId; // now populated by the relay
 *   }
 *
 * ## Reverse lookup from SNS webhooks
 *
 *   // AWS delivers a bounce notification referencing the SES MessageId:
 *   $entry = $store->findByAwsMessageId($sesMessageId);
 */
interface EmailOutboxStoreInterface
{
    /**
     * Find an outbox row by its stable UUID (the value returned from SentEmail::messageId()
     * when the outbox driver is active).
     */
    public function findById(string $outboxId): ?OutboxEntry;

    /**
     * Find an outbox row by the real AWS SES MessageId written back after relay delivery.
     * Returns null if the row does not exist or has not yet been sent by the relay.
     */
    public function findByAwsMessageId(string $awsMessageId): ?OutboxEntry;
}
