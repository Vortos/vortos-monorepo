<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Operation;

use Vortos\Paddle\ValueObject\PaddlePriceId;

final class PricePreviewItem
{
    public function __construct(
        public readonly PaddlePriceId $priceId,
        public readonly int           $quantity,
    ) {}
}
