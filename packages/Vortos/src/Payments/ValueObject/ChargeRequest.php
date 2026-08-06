<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

/**
 * Everything a rail needs to open a checkout, in a form no rail can
 * misinterpret.
 *
 * ── The invariant ─────────────────────────────────────────────────────────
 * `Σ(lines) == total`, exactly, in integer minor units, in one currency. It is
 * asserted in the constructor, so a request whose lines do not add up to what
 * the payer will be charged cannot be built — not for Paddle, not for PayHere,
 * not for a rail that does not exist yet.
 *
 * This mirrors the same assertion made on the pricing snapshot upstream. Two
 * independent checks of one arithmetic identity is not redundancy: the
 * upstream one guarantees the *price* reconciles, this one guarantees nothing
 * mangled it on the way to the rail.
 *
 * ── The reference ─────────────────────────────────────────────────────────
 * `reference` is our identifier, echoed back on the webhook. It is how a
 * settlement finds the payment it settles. It must be unique per charge
 * attempt and must carry no meaning that anything parses — rails truncate,
 * upper-case and re-encode it, and a reference that has to survive that
 * intact is a bug waiting for its first long organisation name.
 */
final readonly class ChargeRequest
{
    /**
     * @param list<ChargeLine>      $lines
     * @param array<string, string> $metadata Echoed back by the rail where supported.
     */
    public function __construct(
        public string       $reference,
        public Money        $total,
        public array        $lines,
        public PayerDetails $payer,
        /** Where the payer lands after paying. Required by redirect rails. */
        public ?string      $returnUrl = null,
        /** Where the payer lands after abandoning. Required by redirect rails. */
        public ?string      $cancelUrl = null,
        public array        $metadata = [],
    ) {
        if (trim($reference) === '') {
            throw new \InvalidArgumentException('A charge needs a reference; it is how the webhook finds the payment.');
        }

        if ($lines === []) {
            throw new \InvalidArgumentException('A charge needs at least one line; the payer is entitled to know what they are paying for.');
        }

        $sum = Money::zero($total->currency);
        foreach ($lines as $line) {
            // Money::plus refuses a currency mismatch, which is exactly the
            // check wanted here: a fee line denominated in the organiser's
            // currency while the base was converted is a real bug, and it
            // should surface as a mismatch rather than as a wrong total.
            $sum = $sum->plus($line->total());
        }

        if (!$sum->equals($total)) {
            throw new \InvalidArgumentException(sprintf(
                'Charge lines sum to %d minor %s but the total is %d; a charge must reconcile exactly.',
                $sum->minorUnits,
                $total->currency->code,
                $total->minorUnits,
            ));
        }

        if ($total->isZero()) {
            throw new \InvalidArgumentException('Refusing to open a checkout for zero; a free registration does not need a rail.');
        }
    }

    public function currency(): Currency
    {
        return $this->total->currency;
    }
}
