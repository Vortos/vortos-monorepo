<?php

declare(strict_types=1);

namespace Vortos\AwsSes\ValueObject;

use Vortos\AwsSes\Exception\InvalidEmailAddressException;

final class EmailAddress
{
    private string $address;
    private ?string $name;

    public function __construct(string $address, ?string $name = null)
    {
        $normalized = strtolower(trim($address));

        if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw InvalidEmailAddressException::forAddress($address);
        }

        $this->address = $normalized;
        $this->name    = ($name !== null && trim($name) !== '') ? trim($name) : null;
    }

    public static function fromString(string $address, ?string $name = null): self
    {
        return new self($address, $name);
    }

    public function address(): string
    {
        return $this->address;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Returns RFC 5321 formatted address.
     * With name: "Display Name <user@example.com>"
     * Without:   "user@example.com"
     */
    public function toString(): string
    {
        if ($this->name === null) {
            return $this->address;
        }

        return sprintf('"%s" <%s>', addslashes($this->name), $this->address);
    }

    public function equals(self $other): bool
    {
        return $this->address === $other->address;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
