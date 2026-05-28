<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

use Vortos\AwsSes\Webhook\BounceNotification;

/**
 * Handles SES bounce notifications delivered via SNS.
 *
 * Register your handler with #[AsBounceHandler].
 * The framework auto-suppresses hard bounces before dispatching to user handlers.
 */
interface BounceHandlerInterface
{
    public function handle(BounceNotification $notification): void;
}
