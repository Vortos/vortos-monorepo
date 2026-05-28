<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;
use Vortos\AwsSes\Contract\EmailOutboxWriterInterface;
use Vortos\AwsSes\Contract\ImmediateMailerInterface;
use Vortos\AwsSes\Contract\StandaloneMailerInterface;
use Vortos\AwsSes\Exception\OutboxWriteException;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\Persistence\Transaction\ActiveTransactionGuard;

/**
 * Writes an email intent to the aws_ses_outbox table.
 *
 * MUST be called within the caller's active database transaction so that the
 * outbox row and the domain state changes are committed atomically.
 *
 * When domain_event_id is supplied and a row with that ID already exists,
 * the write is silently ignored (idempotent). This protects against duplicate
 * sends when Kafka delivers an event more than once.
 */
final class EmailOutboxWriter implements EmailOutboxWriterInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
        private ?ActiveTransactionGuard $transactionGuard = null,
    ) {}

    public function queue(Email $email, ?string $domainEventId = null): void
    {
        $now = new DateTimeImmutable();
        $this->guard()->assertActive('SES transactional outbox write', StandaloneMailerInterface::class, ImmediateMailerInterface::class);

        try {
            $this->connection->insert($this->tableName, [
                'id'              => Uuid::v7()->toRfc4122(),
                'domain_event_id' => $domainEventId,
                'status'          => OutboxStatus::Pending->value,
                'attempt_count'   => 0,
                'payload'         => json_encode(EmailSerializer::toArray($email), JSON_THROW_ON_ERROR),
                'message_id'      => null,
                'last_error'      => null,
                'next_attempt_at' => null,
                'created_at'      => $now->format('Y-m-d H:i:s.u'),
                'sent_at'         => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Idempotent: row already exists for this domain_event_id — nothing to do
        } catch (\Throwable $e) {
            throw new OutboxWriteException(
                sprintf('Failed to queue email to outbox: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }

    private function guard(): ActiveTransactionGuard
    {
        return $this->transactionGuard ??= new ActiveTransactionGuard($this->connection);
    }
}
