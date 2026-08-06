<?php

declare(strict_types=1);

namespace Vortos\Payments\Exception;

/**
 * A money value that cannot exist. Always a programming error, never a payer's
 * or a gateway's fault — it is thrown before anything is charged.
 */
final class InvalidMoneyException extends PaymentsException
{
    public static function negative(int $minorUnits, string $currency): self
    {
        return new self(sprintf(
            'Money cannot be negative (%d %s). Carry direction in the type that moves the money, not in its sign.',
            $minorUnits,
            $currency,
        ));
    }

    public static function negativeFactor(int $factor): self
    {
        return new self(sprintf('Cannot multiply money by a negative factor (%d).', $factor));
    }

    public static function badCurrencyCode(string $code): self
    {
        return new self(sprintf('"%s" is not an ISO 4217 alphabetic currency code.', $code));
    }

    public static function currencyMismatch(string $left, string $right): self
    {
        return new self(sprintf(
            'Cannot combine %s and %s. Converting between currencies is a priced decision, not an arithmetic one.',
            $left,
            $right,
        ));
    }
}
