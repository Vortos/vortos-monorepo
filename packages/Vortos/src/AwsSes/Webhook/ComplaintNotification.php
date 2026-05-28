<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Webhook;

use DateTimeImmutable;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class ComplaintNotification
{
    public function __construct(
        private readonly EmailAddress $recipient,
        private readonly ?string $complaintFeedbackType,
        private readonly DateTimeImmutable $timestamp,
        private readonly ?string $feedbackId = null,
        private readonly ?string $userAgent = null,
    ) {}

    public function recipient(): EmailAddress { return $this->recipient; }

    /** ISP-reported feedback type: 'abuse', 'fraud', 'virus', 'other', or null if unknown. */
    public function complaintFeedbackType(): ?string { return $this->complaintFeedbackType; }

    public function timestamp(): DateTimeImmutable { return $this->timestamp; }

    public function feedbackId(): ?string { return $this->feedbackId; }

    public function userAgent(): ?string { return $this->userAgent; }
}
