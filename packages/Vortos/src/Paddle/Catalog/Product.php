<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Vortos\Paddle\ValueObject\PaddleProductId;
use Vortos\Paddle\ValueObject\ProductStatus;

final class Product
{
    public function __construct(
        public readonly PaddleProductId   $id,
        public readonly string            $name,
        public readonly ?string           $description,
        public readonly string            $taxCategory,
        public readonly ProductStatus     $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Product $sdk): self
    {
        return new self(
            id:          PaddleProductId::of($sdk->id),
            name:        $sdk->name,
            description: $sdk->description,
            taxCategory: $sdk->taxCategory->getValue(),
            status:      ProductStatus::from($sdk->status->getValue()),
            createdAt:   \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt:   \DateTimeImmutable::createFromInterface($sdk->updatedAt),
        );
    }
}
