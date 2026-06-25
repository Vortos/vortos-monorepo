<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

final readonly class CanaryInstantResult
{
    public function __construct(
        public readonly ?float $value,
        public readonly int $sampleCount,
    ) {}

    public function isEmpty(): bool
    {
        return $this->value === null;
    }

    public static function empty(): self
    {
        return new self(null, 0);
    }
}
