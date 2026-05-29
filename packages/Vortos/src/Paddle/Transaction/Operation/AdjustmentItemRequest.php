<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Operation;

final class AdjustmentItemRequest
{
    public function __construct(
        public readonly string $lineItemId,
        public readonly string $amount,
    ) {}
}
