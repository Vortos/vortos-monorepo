<?php
declare(strict_types=1);
namespace Vortos\Messaging\DeadLetter;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Writes unprocessable messages to the failed_messages store.
 * Called by ConsumerRunner after all retry attempts are exhausted.
 * Currently logs the failure — Phase 12 adds DBAL persistence.
 */
final class DeadLetterWriter
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private string $table = 'vortos_failed_messages'
    ) {}

    public function write(
        string $transportName,
        string $eventClass,
        string $payload,
        array $headers,
        string $failureReason,
        string $exceptionClass,
        int $attemptCount
    ): void {
        $this->logger->critical('Message dead-lettered', [
            'transport'   => $transportName,
            'event_class' => $eventClass,
            'reason'      => $failureReason,
            'attempts'    => $attemptCount,
        ]);

        try {
            $this->connection->insert($this->table, [
                'id'              => (string) new UuidV7(),
                'transport_name'  => $transportName,
                'event_class'     => $eventClass,
                'payload'         => $payload,
                'headers'         => json_encode($headers, JSON_THROW_ON_ERROR),
                'status' => 'failed',
                'failure_reason'  => $failureReason,
                'exception_class' => $exceptionClass,
                'attempt_count'   => $attemptCount,
                'failed_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist dead letter entry', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}