<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Vortos\Paddle\ValueObject\PaddlePriceId;

final class PricePreviewResultItem
{
    public function __construct(
        public readonly PaddlePriceId $priceId,
        public readonly int           $quantity,
        public readonly string        $subtotal,
        public readonly string        $tax,
        public readonly string        $total,
        public readonly string        $currencyCode,
    ) {}
}
