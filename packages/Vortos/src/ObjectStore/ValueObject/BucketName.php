<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class BucketName implements \Stringable
{
    public function __construct(private readonly string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Bucket name cannot be empty.');
        }
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
