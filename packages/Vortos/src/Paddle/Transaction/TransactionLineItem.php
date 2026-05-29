<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

final class TransactionLineItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $priceId,
        public readonly int    $quantity,
        public readonly string $total,
        public readonly string $subtotal,
        public readonly string $tax,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Transaction\TransactionLineItem $sdk): self
    {
        return new self(
            id:       $sdk->id,
            priceId:  $sdk->priceId,
            quantity: $sdk->quantity,
            total:    $sdk->totals->total,
            subtotal: $sdk->totals->subtotal,
            tax:      $sdk->totals->tax,
        );
    }
}
