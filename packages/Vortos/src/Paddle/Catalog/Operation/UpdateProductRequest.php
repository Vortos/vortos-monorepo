<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Operation;

final class UpdateProductRequest
{
    public function __construct(
        public readonly ?string $name        = null,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl    = null,
        public readonly ?string $taxCategory = null,
    ) {}
}
