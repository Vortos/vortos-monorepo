<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

final class WebhookDeliveryRecord
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $subscriptionId,
        public readonly string $eventType,
        public readonly int $httpStatus,
        public readonly bool $success,
        public readonly int $attempt,
        public readonly ?string $errorMessage = null,
        public readonly \DateTimeImmutable $attemptedAt = new \DateTimeImmutable(),
    ) {}
}
