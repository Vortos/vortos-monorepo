<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

use Vortos\ObjectStore\Exception\InvalidObjectKeyException;

final class ObjectKey implements \Stringable
{
    private string $value;

    public function __construct(string $key)
    {
        $key = str_replace('\\', '/', trim($key));
        $key = ltrim($key, '/');

        if ($key === '') {
            throw new InvalidObjectKeyException('Object key cannot be empty.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidObjectKeyException('Object key cannot contain control characters.');
        }

        $segments = explode('/', $key);
        if (in_array('..', $segments, true)) {
            throw new InvalidObjectKeyException('Object key cannot contain parent-directory segments.');
        }

        $this->value = implode('/', array_values(array_filter($segments, static fn(string $s): bool => $s !== '')));
    }

    public static function from(string|self $key): self
    {
        return $key instanceof self ? $key : new self($key);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function withPrefix(string $prefix): self
    {
        $prefix = trim($prefix, '/');

        if ($prefix === '') {
            return $this;
        }

        return new self($prefix . '/' . $this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
