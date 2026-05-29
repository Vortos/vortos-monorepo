<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription\Operation;

final class PauseSubscriptionRequest
{
    public function __construct(
        public readonly ?\DateTimeImmutable $effectiveFrom = null,
    ) {}
}
