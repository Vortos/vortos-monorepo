<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

use Vortos\Payments\Exception\InvalidMoneyException;

/**
 * An ISO 4217 currency, carrying the one property that matters to a payment
 * rail: how many minor units make a major one.
 *
 * ── Why the exponent lives here and not in a formatter ────────────────────
 * A gateway that takes a decimal string — PayHere does, and its signature hash
 * covers that string — needs `600000` minor units rendered as exactly
 * "6000.00". Get the exponent wrong and the request is either rejected (a
 * failed checkout, loud) or accepted for a hundredth of the intended amount
 * (a settled payment for the wrong money, silent). The second outcome is why
 * this is a typed value object with an explicit table rather than an assumed
 * ×100 in whichever adapter happens to need it.
 *
 * The table lists only the currencies that are *not* two-decimal. Everything
 * else defaults to 2, which is correct for the overwhelming majority and, for
 * a currency genuinely missing from the table, wrong in the same direction as
 * the rest of the world's software — so it fails against the gateway rather
 * than silently against the payer.
 */
final readonly class Currency
{
    /**
     * Currencies whose ISO 4217 minor unit is not 1/100.
     *
     * @var array<string, int>
     */
    private const EXPONENTS = [
        // Zero-decimal — the amount *is* the minor unit. Rendering these with
        // two decimals inflates a charge a hundredfold.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0,
        'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0,
        'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0,
        'XOF' => 0, 'XPF' => 0,

        // Three-decimal.
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3,
        'OMR' => 3, 'TND' => 3,
    ];

    private function __construct(
        /** Upper-case ISO 4217 alphabetic code. */
        public string $code,
    ) {}

    public static function of(string $code): self
    {
        $normalised = strtoupper(trim($code));

        // Deliberately stricter than "three characters": a numeric or padded
        // code reaching a gateway produces a rejection whose message names the
        // gateway's field, not ours, and costs an afternoon to trace back.
        if (preg_match('/^[A-Z]{3}$/', $normalised) !== 1) {
            throw InvalidMoneyException::badCurrencyCode($code);
        }

        return new self($normalised);
    }

    /** ISO 4217 exponent — the number of decimal places. */
    public function exponent(): int
    {
        return self::EXPONENTS[$this->code] ?? 2;
    }

    /** Minor units in one major unit: 1, 100 or 1000. */
    public function minorUnitsPerMajor(): int
    {
        return 10 ** $this->exponent();
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
