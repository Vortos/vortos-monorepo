<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

/**
 * The observable, auditable outcome of a delivery attempt — always returned, never
 * thrown (the never-throws contract is TCK-enforced on {@see NotifierInterface}).
 */
final readonly class NotificationResult
{
    private function __construct(
        public NotificationOutcome $outcome,
        public string $channelKey,
        public ?string $reason,
    ) {}

    public static function delivered(string $channelKey): self
    {
        return new self(NotificationOutcome::Delivered, $channelKey, null);
    }

    public static function suppressed(string $channelKey, string $reason): self
    {
        return new self(NotificationOutcome::Suppressed, $channelKey, $reason);
    }

    public static function deduped(string $channelKey, string $reason): self
    {
        return new self(NotificationOutcome::Deduped, $channelKey, $reason);
    }

    public static function rateLimited(string $channelKey, string $reason): self
    {
        return new self(NotificationOutcome::RateLimited, $channelKey, $reason);
    }

    public static function failed(string $channelKey, string $reason): self
    {
        return new self(NotificationOutcome::Failed, $channelKey, $reason);
    }

    public function isSuccess(): bool
    {
        return $this->outcome->isSuccess();
    }
}
