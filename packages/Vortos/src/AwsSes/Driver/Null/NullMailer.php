<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Driver\Null;

use DateTimeImmutable;
use Symfony\Component\Uid\UuidV7;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Silent no-op mailer for testing.
 *
 * Does not validate the email, does not call any external service.
 * Returns a SentEmail with a fake message ID so callers can assert on it.
 *
 * When VORTOS_MAILER_DRIVER=null, AwsSesExtension binds this as MailerInterface.
 * Use SesMailerFake (Phase 13) in tests that need to assert on what was sent.
 */
final class NullMailer implements MailerInterface
{
    public function send(Email $email): SentEmail
    {
        return new SentEmail(
            messageId: 'null-' . (new UuidV7())->toRfc4122(),
            sentAt: new DateTimeImmutable(),
            recipientCount: count($email->getAllRecipients()),
            driver: 'null',
            region: null,
        );
    }
}
