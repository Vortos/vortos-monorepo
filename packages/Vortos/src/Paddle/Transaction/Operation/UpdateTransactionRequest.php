<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction\Operation;

final class UpdateTransactionRequest
{
    /**
     * @param TransactionItemRequest[]|null $items
     */
    public function __construct(
        public readonly ?array  $items = null,
        public readonly ?string $status = null,
    ) {}
}
