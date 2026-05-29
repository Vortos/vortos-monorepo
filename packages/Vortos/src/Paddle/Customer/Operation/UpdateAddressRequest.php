<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Operation;

final class UpdateAddressRequest
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $firstLine   = null,
        public readonly ?string $city        = null,
        public readonly ?string $postalCode  = null,
        public readonly ?string $countryCode = null,
    ) {}
}
