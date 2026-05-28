<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class ContentType implements \Stringable
{
    public function __construct(private readonly string $value)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*(?:\s*;\s*[^;]+)*$/i', $value)) {
            throw new \InvalidArgumentException(sprintf('Invalid content type: %s', $value));
        }
    }

    public static function from(?string $value): ?self
    {
        return $value === null || $value === '' ? null : new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
