<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Operation;

use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class CreateCreditRequest
{
    /**
     * @param AdjustmentItemRequest[] $items
     */
    public function __construct(
        public readonly PaddleTransactionId $transactionId,
        public readonly string              $reason,
        public readonly array               $items,
    ) {}
}
