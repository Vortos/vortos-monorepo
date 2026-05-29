<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Operation;

use Vortos\Paddle\ValueObject\Money;

final class UpdatePriceRequest
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $name        = null,
        public readonly ?Money  $unitPrice   = null,
    ) {}
}
