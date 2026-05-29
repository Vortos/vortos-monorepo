<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Operation;

final class CreateCustomerRequest
{
    public function __construct(
        public readonly string  $email,
        public readonly ?string $name   = null,
        public readonly ?string $locale = null,
    ) {}
}
