<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Operation;

final class PricePreviewRequest
{
    /**
     * @param PricePreviewItem[] $items
     */
    public function __construct(
        public readonly array   $items,
        public readonly ?string $currencyCode       = null,
        public readonly ?string $countryCode        = null,
        public readonly ?string $customerId         = null,
        public readonly ?string $discountId         = null,
    ) {}
}
