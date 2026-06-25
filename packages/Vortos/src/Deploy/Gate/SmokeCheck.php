<?php

declare(strict_types=1);

namespace Vortos\Deploy\Gate;

final readonly class SmokeCheck
{
    public function __construct(
        public string $path,
        public int $expectedStatus = 200,
        public ?float $maxLatencySeconds = null,
    ) {
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('Smoke check path must start with /.');
        }
    }
}
