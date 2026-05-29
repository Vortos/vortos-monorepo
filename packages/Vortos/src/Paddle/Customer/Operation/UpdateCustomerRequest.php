<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Operation;

final class UpdateCustomerRequest
{
    public function __construct(
        public readonly ?string $name   = null,
        public readonly ?string $email  = null,
        public readonly ?string $locale = null,
    ) {}
}
