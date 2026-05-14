<?php
declare(strict_types=1);
namespace Vortos\Messaging\DeadLetter;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\UuidV7;
use Vortos\Messaging\Contract\PayloadSanitizerInterface;

/**
 * Writes unprocessable messages to the vortos_failed_messages table.
 * Called by ConsumerRunner after all retry attempts are exhausted.
 * Always logs the failure first, then persists — log survives a DB outage.
 * Returns true on success, false if persistence failed (caller must not commit offset).
 */
final class DeadLetterWriter
{
    private const MAX_REASON_LENGTH = 2000;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private string $table = 'vortos_failed_messages',
        private ?PayloadSanitizerInterface $sanitizer = null,
    ) {}

    public function write(
        string $transportName,
        string $eventClass,
        string $handlerId,
        string $payload,
        array $headers,
        string $failureReason,
        string $exceptionClass,
        int $attemptCount
    ): bool {
        $this->logger->critical('Message dead-lettered', [
            'transport'   => $transportName,
            'event_class' => $eventClass,
            'reason'      => $failureReason,
            'attempts'    => $attemptCount,
        ]);

        $sanitizedPayload = $this->sanitizer !== null
            ? $this->sanitizer->sanitize($payload, $headers)
            : $payload;

        try {
            $this->connection->insert($this->table, [
                'id'              => (string) new UuidV7(),
                'transport_name'  => $transportName,
                'event_class'     => $eventClass,
                'handler_id'      => $handlerId,
                'payload'         => $sanitizedPayload,
                'headers'         => json_encode($headers, JSON_THROW_ON_ERROR),
                'status'          => 'failed',
                'failure_reason'  => $this->sanitizeReason($failureReason),
                'exception_class' => $exceptionClass,
                'attempt_count'   => $attemptCount,
                'failed_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist dead letter entry', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Strips control characters and truncates to prevent attacker-controlled
     * exception messages (which may contain raw payload fragments) from being
     * stored verbatim in the database.
     */
    private function sanitizeReason(string $reason): string
    {
        // Strip control characters except tab (\x09) and newline (\x0A)
        $sanitized = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $reason) ?? '';

        if (mb_strlen($sanitized) <= self::MAX_REASON_LENGTH) {
            return $sanitized;
        }

        return mb_substr($sanitized, 0, self::MAX_REASON_LENGTH) . ' [truncated]';
    }
}