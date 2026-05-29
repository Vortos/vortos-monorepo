<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

final class PaddleAddressId
{
    private function __construct(public readonly string $value) {}

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
