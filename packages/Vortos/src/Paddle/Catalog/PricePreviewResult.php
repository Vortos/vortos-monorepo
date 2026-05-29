<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

final class PricePreviewResult
{
    /**
     * @param PricePreviewResultItem[] $items
     */
    public function __construct(
        public readonly string $currencyCode,
        public readonly array  $items,
    ) {}
}
