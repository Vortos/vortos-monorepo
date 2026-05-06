<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsHealthCheck
{
    public function __construct(
        public readonly bool $critical = true,
        public readonly int $timeoutMs = 5000,
    ) {}
}
