<?php

declare(strict_types=1);

namespace Vortos\Push\Contract;

/**
 * Outcome of a single send. `status` tells the caller whether the subscription
 * should be revoked (Gone) or retried (Failed); `httpStatus`/`error` are
 * diagnostics.
 */
final class WebPushResult
{
    public function __construct(
        public readonly WebPushDeliveryStatus $status,
        public readonly int $httpStatus = 0,
        public readonly ?string $error = null,
    ) {}

    public function isDelivered(): bool
    {
        return $this->status === WebPushDeliveryStatus::Delivered;
    }

    public function isGone(): bool
    {
        return $this->status === WebPushDeliveryStatus::Gone;
    }
}
