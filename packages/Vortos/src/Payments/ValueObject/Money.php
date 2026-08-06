<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

use Vortos\Payments\Exception\InvalidMoneyException;

/**
 * An amount of money, as an integer count of minor units.
 *
 * There is no float anywhere in this class and there must never be one. 0.1
 * is not representable in binary floating point; a fee that is off by one
 * minor unit is a ledger that does not reconcile, and a ledger that does not
 * reconcile is indistinguishable from theft when someone eventually audits it.
 *
 * Non-negative by construction. A refund or an adjustment is a positive amount
 * carried by a type that says which direction it moves — sign smuggled inside
 * a Money is how a credit ends up booked as a debit.
 */
final readonly class Money
{
    private function __construct(
        public int      $minorUnits,
        public Currency $currency,
    ) {
        if ($minorUnits < 0) {
            throw InvalidMoneyException::negative($minorUnits, $currency->code);
        }
    }

    public static function fromMinor(int $minorUnits, Currency|string $currency): self
    {
        return new self(
            $minorUnits,
            $currency instanceof Currency ? $currency : Currency::of($currency),
        );
    }

    public static function zero(Currency|string $currency): self
    {
        return self::fromMinor(0, $currency);
    }

    /**
     * The amount as a fixed-point decimal string: 600000 minor LKR → "6000.00".
     *
     * Built by string surgery on the integer rather than by dividing, because
     * division introduces the float this class exists to keep out — and because
     * on rails like PayHere this exact string is covered by the request
     * signature, so "6000.0000001" is not a cosmetic difference, it is a
     * rejected checkout.
     */
    public function toDecimalString(): string
    {
        $exponent = $this->currency->exponent();

        if ($exponent === 0) {
            return (string) $this->minorUnits;
        }

        $digits = str_pad((string) $this->minorUnits, $exponent + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    /** @throws InvalidMoneyException when the result would be negative. */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        if ($factor < 0) {
            throw InvalidMoneyException::negativeFactor($factor);
        }

        return new self($this->minorUnits * $factor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency->equals($other->currency);
    }

    /** @return array{minor_units: int, currency: string} */
    public function toArray(): array
    {
        return ['minor_units' => $this->minorUnits, 'currency' => $this->currency->code];
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw InvalidMoneyException::currencyMismatch($this->currency->code, $other->currency->code);
        }
    }
}
