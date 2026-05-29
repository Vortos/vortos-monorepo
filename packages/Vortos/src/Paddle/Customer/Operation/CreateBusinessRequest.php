<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Operation;

use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class CreateBusinessRequest
{
    public function __construct(
        public readonly PaddleCustomerId $customerId,
        public readonly string           $name,
        public readonly ?string          $companyNumber  = null,
        public readonly ?string          $taxIdentifier  = null,
    ) {}
}
