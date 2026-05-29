<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription;

use Vortos\Paddle\ValueObject\PaddleSubscriptionId;

final class SubscriptionUpdatePreview
{
    public function __construct(
        public readonly PaddleSubscriptionId $subscriptionId,
        public readonly string               $immediateTotal,
        public readonly string               $nextBillingTotal,
        public readonly string               $currencyCode,
    ) {}
}
