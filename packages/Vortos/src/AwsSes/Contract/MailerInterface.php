<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

use Vortos\AwsSes\Exception\MailSendException;
use Vortos\AwsSes\Exception\RateLimitExceededException;
use Vortos\AwsSes\Exception\SuppressionListException;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Primary application email contract.
 *
 * Implementations run the full middleware stack (suppression check,
 * deduplication, rate limiting, logging, tracing) before hitting the
 * underlying transport (SES, log, or null).
 *
 * When the outbox is enabled this interface is the transactional path: send()
 * writes an outbox row and requires the caller to already be inside the
 * CommandBus/UnitOfWork transaction. Use StandaloneMailerInterface for a
 * standalone async outbox write, and ImmediateMailerInterface for a direct
 * provider call with no outbox.
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
