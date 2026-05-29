<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Operation;

final class UpdateDiscountRequest
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $amount      = null,
        public readonly ?string $code        = null,
        public readonly ?int    $usageLimit  = null,
    ) {}
}
