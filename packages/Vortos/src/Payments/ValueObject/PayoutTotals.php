<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

/**
 * What a settled transaction actually became, in the currency the rail pays us
 * in.
 *
 * Distinct from what the payer was billed. A rail may bill USD and settle USD
 * (Paddle), or bill LKR and settle LKR (PayHere), or bill one and settle
 * another after its own conversion — and only this object knows which, because
 * only this object is populated after the money has moved.
 *
 * `gross` and `fee` are what the rail reports; `earnings` is what it says
 * landed. They are not re-derived here: if a rail's own three numbers do not
 * subtract, that is a fact worth reconciling, not a fact worth papering over
 * with arithmetic we invented.
 */
final readonly class PayoutTotals
{
    public function __construct(
        public Money        $gross,
        public ProcessorFee $fee,
        public Money        $earnings,
        /**
         * The rail's own conversion rate, verbatim as a string.
         *
         * A string because it arrives as one and because parsing it to a float
         * to store it and back to display it is two lossy steps in service of
         * nothing — nothing in the money path multiplies by it.
         */
        public ?string      $exchangeRate = null,
    ) {}

    /**
     * Whether the rail's own numbers are internally consistent.
     *
     * False is not necessarily an error — some rails report gross before an
     * adjustment that earnings already reflects — but it is always worth
     * surfacing rather than assuming.
     */
    public function isSelfConsistent(): bool
    {
        if (!$this->fee->isKnown) {
            return true;
        }

        $fee = $this->fee->amountOrFail();

        if (!$fee->currency->equals($this->gross->currency)
            || !$this->earnings->currency->equals($this->gross->currency)) {
            return false;
        }

        return $this->gross->minorUnits - $fee->minorUnits === $this->earnings->minorUnits;
    }
}
