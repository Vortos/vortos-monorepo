<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription\Operation;

final class CancelSubscriptionRequest
{
    public function __construct(
        public readonly ?\DateTimeImmutable $effectiveFrom = null,
    ) {}
}
