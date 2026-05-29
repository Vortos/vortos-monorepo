<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer;

use Vortos\Paddle\ValueObject\CustomerStatus;
use Vortos\Paddle\ValueObject\PaddleAddressId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class Address
{
    public function __construct(
        public readonly PaddleAddressId   $id,
        public readonly PaddleCustomerId  $customerId,
        public readonly ?string           $description,
        public readonly ?string           $firstLine,
        public readonly ?string           $city,
        public readonly ?string           $postalCode,
        public readonly string            $countryCode,
        public readonly CustomerStatus    $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Address $sdk): self
    {
        return new self(
            id:          PaddleAddressId::of($sdk->id),
            customerId:  PaddleCustomerId::of($sdk->customerId),
            description: $sdk->description,
            firstLine:   $sdk->firstLine,
            city:        $sdk->city,
            postalCode:  $sdk->postalCode,
            countryCode: (string) $sdk->countryCode,
            status:      CustomerStatus::from($sdk->status->getValue()),
            createdAt:   \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt:   \DateTimeImmutable::createFromInterface($sdk->updatedAt),
        );
    }
}
