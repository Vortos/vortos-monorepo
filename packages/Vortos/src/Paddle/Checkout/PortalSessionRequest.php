<?php

declare(strict_types=1);

namespace Vortos\Paddle\Checkout;

use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;

final class PortalSessionRequest
{
    public function __construct(
        public readonly PaddleCustomerId     $customerId,
        public readonly string               $returnUrl,
        public readonly ?PaddleSubscriptionId $subscriptionId = null,
    ) {}
}
