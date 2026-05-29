<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription\Operation;

use Vortos\Paddle\ValueObject\ProrationMode;

final class UpdateSubscriptionRequest
{
    /**
     * @param array<SubscriptionItemRequest>|null $items
     */
    public function __construct(
        public readonly ?array         $items            = null,
        public readonly ?ProrationMode $prorationMode    = null,
        public readonly ?string        $nextBilledAt     = null,
    ) {}
}
