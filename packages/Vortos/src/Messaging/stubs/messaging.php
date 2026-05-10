<?php

declare(strict_types=1);

use Vortos\Messaging\DependencyInjection\VortosMessagingConfig;

// The messaging driver is chosen by VORTOS_MESSAGING_DRIVER in .env:
//   VORTOS_MESSAGING_DRIVER=kafka      → Kafka (prod)
//   VORTOS_MESSAGING_DRIVER=in-memory  → InMemoryDriver (dev/test, no broker needed)
//
// This file configures messaging behaviour: outbox, dead-letter queue,
// and consumer defaults. Driver-specific options (brokers, topics) are
// registered per-consumer using #[AsConsumer] attributes.
//
// For per-environment overrides create config/{env}/messaging.php.

return static function (VortosMessagingConfig $config): void {
    // Transactional outbox — guarantees at-least-once delivery by writing
    // messages to the DB inside the same transaction as the domain change.
    // The outbox relay worker then publishes them to the broker.
    $config->outbox()
        // DB table used to store pending outbox messages.
        ->table('vortos_outbox')

        // Maximum publish attempts before a message is moved to the DLQ.
        ->maxAttempts(5)

        // Initial retry delay in seconds. Doubles on each failure (exponential backoff).
        ->backoffBase(30)

        // Maximum retry delay cap in seconds. 3600 = 1 hour.
        ->backoffCap(3600)
    ;

    // Dead-letter queue — stores messages that exceeded maxAttempts.
    $config->dlq()
        // DB table used to store failed messages.
        ->table('vortos_failed_messages')
    ;

    // Default settings applied to all consumers unless overridden per-consumer.
    $config->consumerDefaults()
        // Deduplication window in seconds.
        // Messages with the same ID received within this window are skipped.
        // 86400 = 24 hours.
        ->idempotencyTtl(86400)
    ;
};
