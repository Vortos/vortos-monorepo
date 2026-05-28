<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Bounce;

use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Contract\BounceHandlerInterface;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\Webhook\BounceNotification;

/**
 * Framework-provided bounce handler that auto-suppresses hard bounces.
 *
 * Hard bounces (Permanent type) indicate that the email address is definitively
 * invalid. Auto-suppression prevents future sends, protects sender reputation,
 * and satisfies AWS's SES sending policies (repeated hard bounces risk account suspension).
 *
 * Soft bounces (Transient/Undetermined) are logged but not suppressed — a
 * temporary delivery failure (mailbox full, server unavailable) does not mean
 * the address is invalid.
 *
 * Always runs before user-registered bounce handlers.
 */
final class AutoSuppressionBounceHandler implements BounceHandlerInterface
{
    public function __construct(
        private readonly SuppressionListInterface $suppressionList,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(BounceNotification $notification): void
    {
        if (!$notification->isHardBounce()) {
            $this->logger->info('ses.bounce: soft bounce — not suppressing', [
                'address'    => $notification->recipient()->address(),
                'bounce_type' => $notification->bounceType()->value,
                'sub_type'   => $notification->bounceSubType(),
            ]);
            return;
        }

        $this->suppressionList->suppress($notification->recipient(), SuppressionReason::Bounce);

        $this->logger->warning('ses.bounce: hard bounce — address suppressed', [
            'address'    => $notification->recipient()->address(),
            'sub_type'   => $notification->bounceSubType(),
            'diagnostic' => $notification->diagnosticCode(),
        ]);
    }
}
