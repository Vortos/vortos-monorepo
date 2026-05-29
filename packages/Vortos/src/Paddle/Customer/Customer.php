<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Vortos\Paddle\ValueObject\CustomerStatus;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class Customer
{
    public function __construct(
        public readonly PaddleCustomerId   $id,
        public readonly string             $email,
        public readonly ?string            $name,
        public readonly CustomerStatus     $status,
        public readonly string             $locale,
        public readonly \DateTimeImmutable  $createdAt,
        public readonly \DateTimeImmutable  $updatedAt,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Customer $sdk): self
    {
        return new self(
            id:        PaddleCustomerId::of($sdk->id),
            email:     $sdk->email,
            name:      $sdk->name,
            status:    CustomerStatus::from($sdk->status->getValue()),
            locale:    $sdk->locale,
            createdAt: \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt: \DateTimeImmutable::createFromInterface($sdk->updatedAt),
        );
    }
}
