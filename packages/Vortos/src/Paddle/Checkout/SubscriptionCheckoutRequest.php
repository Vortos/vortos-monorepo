<?php

declare(strict_types=1);

namespace Vortos\Paddle\Checkout;

use Vortos\Paddle\ValueObject\PaddleSubscriptionId;

final class SubscriptionCheckoutRequest
{
    public function __construct(
        public readonly PaddleSubscriptionId $subscriptionId,
    ) {}
}
