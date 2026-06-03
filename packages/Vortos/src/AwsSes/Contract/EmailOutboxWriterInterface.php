<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

use Vortos\AwsSes\Exception\OutboxWriteException;
use Vortos\AwsSes\ValueObject\Email;

/**
 * Writes email intent to the aws_ses_outbox table within the caller's active transaction.
 *
 * Never starts its own transaction. The caller — typically a command handler
 * wrapped by the CQRS TransactionalMiddleware — owns the transaction boundary.
 * The outbox row and the domain changes commit atomically or roll back together.
 *
 * When domain_event_id is supplied the outbox table enforces a UNIQUE constraint
 * on it, preventing duplicate emails even when a Kafka event is delivered twice.
 * Idempotent calls return the existing row's UUID rather than throwing.
 *
 * @return string The outbox row UUID (stable reference for later lookup via EmailOutboxStoreInterface)
 *
 * @throws OutboxWriteException
 */
interface EmailOutboxWriterInterface
{
    public function queue(Email $email, ?string $domainEventId = null): string;
}
