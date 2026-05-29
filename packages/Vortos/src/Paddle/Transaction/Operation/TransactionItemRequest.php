<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Operation;

use Vortos\Paddle\ValueObject\PaddlePriceId;

final class TransactionItemRequest
{
    public function __construct(
        public readonly PaddlePriceId $priceId,
        public readonly int           $quantity,
    ) {}
}
