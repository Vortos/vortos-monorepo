<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

final class Money
{
    public function __construct(
        public readonly int    $amount,
        public readonly string $currencyCode,
    ) {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Money amount cannot be negative');
        }

        if (strlen($currencyCode) !== 3) {
            throw new \InvalidArgumentException('Currency code must be exactly 3 characters (ISO 4217)');
        }
    }

    public function toAmountString(): string
    {
        return (string) $this->amount;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && strtoupper($this->currencyCode) === strtoupper($other->currencyCode);
    }
}
