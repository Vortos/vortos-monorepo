<?php

declare(strict_types=1);

namespace Vortos\Ses\Contract;

use Vortos\Ses\Webhook\ComplaintNotification;

/**
 * Handles SES complaint (spam) notifications delivered via SNS.
 *
 * Register your handler with #[AsComplaintHandler].
 * The framework auto-suppresses complained addresses before dispatching to user handlers.
 */
interface ComplaintHandlerInterface
{
    public function handle(ComplaintNotification $notification): void;
}
