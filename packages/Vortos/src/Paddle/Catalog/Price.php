<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Vortos\Paddle\ValueObject\BillingInterval;
use Vortos\Paddle\ValueObject\Money;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleProductId;
use Vortos\Paddle\ValueObject\ProductStatus;

final class Price
{
    public function __construct(
        public readonly PaddlePriceId      $id,
        public readonly PaddleProductId    $productId,
        public readonly string             $description,
        public readonly ?string            $name,
        public readonly Money              $unitPrice,
        public readonly ProductStatus      $status,
        public readonly ?BillingInterval   $billingInterval,
        public readonly ?int               $billingFrequency,
        public readonly \DateTimeImmutable  $createdAt,
        public readonly \DateTimeImmutable  $updatedAt,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Price $sdk): self
    {
        return new self(
            id:               PaddlePriceId::of($sdk->id),
            productId:        PaddleProductId::of($sdk->productId),
            description:      $sdk->description,
            name:             $sdk->name,
            unitPrice:        new Money((int) $sdk->unitPrice->amount, (string) $sdk->unitPrice->currencyCode),
            status:           ProductStatus::from($sdk->status->getValue()),
            billingInterval:  $sdk->billingCycle !== null
                                  ? BillingInterval::from($sdk->billingCycle->interval->getValue())
                                  : null,
            billingFrequency: $sdk->billingCycle?->frequency,
            createdAt:        \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt:        \DateTimeImmutable::createFromInterface($sdk->updatedAt),
        );
    }
}
