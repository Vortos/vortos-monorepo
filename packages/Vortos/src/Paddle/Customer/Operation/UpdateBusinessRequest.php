<?php

declare(strict_types=1);

namespace Vortos\Paddle\Customer\Operation;

final class UpdateBusinessRequest
{
    public function __construct(
        public readonly ?string $name          = null,
        public readonly ?string $companyNumber = null,
        public readonly ?string $taxIdentifier = null,
    ) {}
}
