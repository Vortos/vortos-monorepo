<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

final class TransactionPreviewResult
{
    public function __construct(
        public readonly string $subtotal,
        public readonly string $tax,
        public readonly string $total,
        public readonly string $currencyCode,
        /** @var TransactionLineItem[] */
        public readonly array  $lineItems,
    ) {}
}
