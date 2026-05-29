<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription\Operation;

use Vortos\Paddle\ValueObject\PaddlePriceId;

final class SubscriptionItemRequest
{
    public function __construct(
        public readonly PaddlePriceId $priceId,
        public readonly int           $quantity,
    ) {}
}
