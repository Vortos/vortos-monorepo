<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Driver\Log;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\UuidV7;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Development/staging mailer — writes a structured log entry instead of calling AWS.
 *
 * All email fields are logged at INFO level so developers can inspect outgoing
 * email in their log stream without needing real AWS credentials or SES access.
 *
 * Does NOT validate the email — that is the middleware stack's responsibility.
 */
final class LogMailer implements MailerInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(Email $email): SentEmail
    {
        $messageId = 'log-' . (new UuidV7())->toRfc4122();

        $this->logger->info('ses.mailer.log: email captured', [
            'driver'       => 'log',
            'message_id'   => $messageId,
            'to'           => array_map(fn($a) => $a->toString(), $email->getTo()),
            'cc'           => array_map(fn($a) => $a->toString(), $email->getCc()),
            'bcc'          => array_map(fn($a) => $a->toString(), $email->getBcc()),
            'from'         => $email->getFrom()?->toString(),
            'reply_to'     => $email->getReplyTo()?->toString(),
            'subject'      => $email->getSubject(),
            'has_html'     => $email->getHtmlBody() !== null,
            'has_text'     => $email->getTextBody() !== null,
            'attachments'  => count($email->getAttachments()),
            'headers'      => $email->getHeaders(),
        ]);

        return new SentEmail(
            messageId: $messageId,
            sentAt: new DateTimeImmutable(),
            recipientCount: count($email->getAllRecipients()),
            driver: 'log',
            region: null,
        );
    }
}
