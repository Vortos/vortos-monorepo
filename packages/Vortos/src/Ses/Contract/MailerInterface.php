<?php

declare(strict_types=1);

namespace Vortos\Ses\Contract;

use Vortos\Ses\Exception\MailSendException;
use Vortos\Ses\Exception\RateLimitExceededException;
use Vortos\Ses\Exception\SuppressionListException;
use Vortos\Ses\ValueObject\Email;
use Vortos\Ses\ValueObject\SentEmail;

/**
 * Primary email delivery contract.
 *
 * Implementations run the full middleware stack (suppression check,
 * deduplication, rate limiting, logging, tracing) before hitting the
 * underlying transport (SES, log, or null).
 *
 * When the outbox is enabled, the application should use EmailOutboxWriterInterface
 * instead of calling this directly from command handlers. MailerInterface is called
 * by the outbox relay worker after the email has been persisted.
 */
interface MailerInterface
{
    /**
     * Send an email through the configured transport.
     *
     * @throws MailSendException          The transport rejected the message.
     * @throws SuppressionListException   One or more recipients are suppressed.
     * @throws RateLimitExceededException Sending rate exceeded and wait timeout expired.
     */
    public function send(Email $email): SentEmail;
}
