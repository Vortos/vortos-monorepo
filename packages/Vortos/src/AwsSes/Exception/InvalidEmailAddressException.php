<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Exception;

final class InvalidEmailAddressException extends \InvalidArgumentException
{
    public static function forAddress(string $address): self
    {
        return new self(sprintf('"%s" is not a valid email address.', $address));
    }
}
