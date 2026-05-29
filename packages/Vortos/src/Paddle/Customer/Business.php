<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Vortos\Paddle\ValueObject\CustomerStatus;
use Vortos\Paddle\ValueObject\PaddleBusinessId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class Business
{
    public function __construct(
        public readonly PaddleBusinessId  $id,
        public readonly PaddleCustomerId  $customerId,
        public readonly string            $name,
        public readonly ?string           $companyNumber,
        public readonly ?string           $taxIdentifier,
        public readonly CustomerStatus    $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Business $sdk): self
    {
        return new self(
            id:            PaddleBusinessId::of($sdk->id),
            customerId:    PaddleCustomerId::of($sdk->customerId),
            name:          $sdk->name,
            companyNumber: $sdk->companyNumber,
            taxIdentifier: $sdk->taxIdentifier,
            status:        CustomerStatus::from($sdk->status->getValue()),
            createdAt:     \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt:     \DateTimeImmutable::createFromInterface($sdk->updatedAt),
        );
    }
}
