<?php

declare(strict_types=1);

namespace Vortos\Ses\Contract;

use Vortos\Ses\ValueObject\Email;
use Vortos\Ses\ValueObject\SentEmail;

/**
 * Observer for email send lifecycle events.
 *
 * Register implementations by tagging them with 'vortos_ses.send_observer'.
 * The HookMiddleware invokes all registered observers before and after each send.
 *
 * Observer errors never bubble up — a failing observer must not break delivery.
 */
interface EmailSendObserverInterface
{
    public function beforeSend(Email $email): void;

    public function afterSend(Email $email, SentEmail $result): void;

    public function onSendError(Email $email, \Throwable $error): void;
}
