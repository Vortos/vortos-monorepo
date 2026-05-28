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
 * Returns a placeholder SentEmail — the real AWS MessageId is not available
 * until the relay sends the email. Callers that need the real MessageId must
 * disable the outbox and inject the sending mailer directly.
 */
final class TransactionalOutboxMailer implements MailerInterface
{
    public function __construct(
        private readonly EmailOutboxWriterInterface $writer,
    ) {}

    public function send(Email $email): SentEmail
    {
        $this->writer->queue($email);

        return new SentEmail(
            messageId:      'outbox-' . uniqid('', more_entropy: true),
            sentAt:         new DateTimeImmutable(),
            recipientCount: count($email->getAllRecipients()),
            driver:         'outbox',
            region:         null,
        );
    }
}
