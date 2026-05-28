<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Webhook;

use DateTimeImmutable;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class BounceNotification
{
    public function __construct(
        private readonly EmailAddress $recipient,
        private readonly BounceType $bounceType,
        private readonly string $bounceSubType,
        private readonly string $diagnosticCode,
        private readonly DateTimeImmutable $timestamp,
        private readonly ?string $feedbackId = null,
    ) {}

    public function recipient(): EmailAddress { return $this->recipient; }

    public function bounceType(): BounceType { return $this->bounceType; }

    public function bounceSubType(): string { return $this->bounceSubType; }

    public function diagnosticCode(): string { return $this->diagnosticCode; }

    public function timestamp(): DateTimeImmutable { return $this->timestamp; }

    public function feedbackId(): ?string { return $this->feedbackId; }

    public function isHardBounce(): bool
    {
        return $this->bounceType === BounceType::Permanent;
    }
}
