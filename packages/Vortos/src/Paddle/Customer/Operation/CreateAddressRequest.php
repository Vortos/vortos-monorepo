<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Operation;

use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class CreateAddressRequest
{
    public function __construct(
        public readonly PaddleCustomerId $customerId,
        public readonly string           $countryCode,
        public readonly ?string          $description = null,
        public readonly ?string          $firstLine   = null,
        public readonly ?string          $city        = null,
        public readonly ?string          $postalCode  = null,
    ) {}
}
