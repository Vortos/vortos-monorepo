<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Operation;

final class CreateProductRequest
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $taxCategory,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl    = null,
    ) {}
}
