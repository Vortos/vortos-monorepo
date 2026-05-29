<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Vortos\Paddle\ValueObject\DiscountStatus;
use Vortos\Paddle\ValueObject\DiscountType;
use Vortos\Paddle\ValueObject\PaddleDiscountId;

final class Discount
{
    public function __construct(
        public readonly PaddleDiscountId   $id,
        public readonly DiscountStatus     $status,
        public readonly string             $description,
        public readonly DiscountType       $type,
        public readonly string             $amount,
        public readonly ?string            $currencyCode,
        public readonly ?string            $code,
        public readonly bool               $recur,
        public readonly int                $timesUsed,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly \DateTimeImmutable  $createdAt,
        public readonly \DateTimeImmutable  $updatedAt,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Discount $sdk): self
    {
        return new self(
            id:           PaddleDiscountId::of($sdk->id),
            status:       DiscountStatus::from($sdk->status->getValue()),
            description:  $sdk->description,
            type:         DiscountType::from($sdk->type->getValue()),
            amount:       $sdk->amount,
            currencyCode: $sdk->currencyCode !== null ? (string) $sdk->currencyCode : null,
            code:         $sdk->code,
            recur:        $sdk->recur,
            timesUsed:    $sdk->timesUsed,
            expiresAt:    $sdk->expiresAt !== null
                              ? \DateTimeImmutable::createFromInterface($sdk->expiresAt)
                              : null,
            createdAt:    \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt:    \DateTimeImmutable::createFromInterface($sdk->updatedAt),
        );
    }
}
