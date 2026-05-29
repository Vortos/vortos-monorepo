<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

final class PriceOverride
{
    public function __construct(
        public readonly string $countryCode,
        public readonly Money  $unitAmount,
    ) {}
}
