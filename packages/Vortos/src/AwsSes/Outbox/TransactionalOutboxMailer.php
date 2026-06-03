<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Outbox;

use DateTimeImmutable;
use Vortos\AwsSes\Contract\EmailOutboxWriterInterface;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Implements MailerInterface by writing to the transactional outbox instead of
 * sending over the network. The EmailOutboxRelay background worker handles
 * actual delivery via the real sending stack.
 *
 * Returns a SentEmail where messageId() is the outbox row UUID — a stable reference
 * that can be stored and later used with EmailOutboxStoreInterface::findById() to
 * retrieve the real AWS MessageId once the relay has delivered the email.
 *
 * SentEmail::isQueued() returns true to signal that delivery is deferred.
 */
final class TransactionalOutboxMailer implements MailerInterface
{
    public function __construct(
        private readonly EmailOutboxWriterInterface $writer,
    ) {}

    public function send(Email $email): SentEmail
    {
        $outboxId = $this->writer->queue($email);

        return new SentEmail(
            messageId:      $outboxId,
            sentAt:         new DateTimeImmutable(),
            recipientCount: count($email->getAllRecipients()),
            driver:         'outbox',
            region:         null,
        );
    }
}
