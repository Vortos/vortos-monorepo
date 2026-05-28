<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Bounce;

use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Contract\ComplaintHandlerInterface;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\Webhook\ComplaintNotification;

/**
 * Framework-provided complaint handler that auto-suppresses complained addresses.
 *
 * When a recipient marks an email as spam, AWS SES delivers a complaint notification.
 * Continuing to send to complained addresses is a strong signal of spam behaviour
 * and risks account suspension. Auto-suppression stops all future sends immediately.
 *
 * Always runs before user-registered complaint handlers.
 */
final class AutoSuppressionComplaintHandler implements ComplaintHandlerInterface
{
    public function __construct(
        private readonly SuppressionListInterface $suppressionList,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(ComplaintNotification $notification): void
    {
        $this->suppressionList->suppress($notification->recipient(), SuppressionReason::Complaint);

        $this->logger->warning('ses.complaint: address suppressed after spam complaint', [
            'address'       => $notification->recipient()->address(),
            'feedback_type' => $notification->complaintFeedbackType(),
            'user_agent'    => $notification->userAgent(),
        ]);
    }
}
