<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Operation;

use Vortos\Paddle\ValueObject\BillingInterval;
use Vortos\Paddle\ValueObject\Money;
use Vortos\Paddle\ValueObject\PaddleProductId;

final class CreatePriceRequest
{
    public function __construct(
        public readonly PaddleProductId  $productId,
        public readonly string           $description,
        public readonly Money            $unitPrice,
        public readonly ?string          $name             = null,
        public readonly ?BillingInterval $billingInterval  = null,
        public readonly ?int             $billingFrequency = null,
    ) {}
}
