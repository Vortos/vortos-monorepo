<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Operation;

use Vortos\Paddle\ValueObject\DiscountType;

final class CreateDiscountRequest
{
    public function __construct(
        public readonly DiscountType $type,
        public readonly string       $amount,
        public readonly string       $description,
        public readonly string       $currencyCode,
        public readonly bool         $enabledForCheckout = true,
        public readonly bool         $recur              = false,
        public readonly ?string      $code               = null,
        public readonly ?int         $usageLimit         = null,
        public readonly ?\DateTimeImmutable $expiresAt   = null,
    ) {}
}
