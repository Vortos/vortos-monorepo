<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

/**
 * Immediate email delivery through the configured transport with no outbox.
 */
interface ImmediateMailerInterface extends MailerInterface
{
}
