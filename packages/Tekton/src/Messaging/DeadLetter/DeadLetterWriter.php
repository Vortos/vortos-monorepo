<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\DeadLetter;

use Psr\Log\LoggerInterface;

/**
 * Writes unprocessable messages to the failed_messages store.
 * Called by ConsumerRunner after all retry attempts are exhausted.
 * Currently logs the failure — Phase 12 adds DBAL persistence.
 */
final class DeadLetterWriter
{
    public function __construct(
        private LoggerInterface $logger
    ){
    }

    public function write(
        string $transportName,
        string $eventClass,
        string $payload,
        array $headers,
        string $failureReason,
        string $exceptionClass,
        int $attemptCount
    ): void {
        // TODO Phase 12: implement DBAL write to failed_messages table
        $this->logger->critical('Message dead-lettered', [
            'transport' => $transportName,
            'event_class' => $eventClass,
            'reason' => $failureReason,
            'attempts' => $attemptCount,
        ]);
    }
}