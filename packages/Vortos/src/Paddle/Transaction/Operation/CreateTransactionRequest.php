<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Operation;

use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class CreateTransactionRequest
{
    /**
     * @param TransactionItemRequest[] $items
     */
    public function __construct(
        public readonly PaddleCustomerId $customerId,
        public readonly array            $items,
        public readonly ?string          $currencyCode = null,
    ) {}
}
