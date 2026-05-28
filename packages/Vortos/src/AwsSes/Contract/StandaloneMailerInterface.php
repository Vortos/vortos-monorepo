<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

/**
 * Reliable async email outside an application transaction.
 *
 * Implementations write to the SES outbox inside their own short transaction.
 * They are not atomic with domain writes.
 */
interface StandaloneMailerInterface extends MailerInterface
{
}
