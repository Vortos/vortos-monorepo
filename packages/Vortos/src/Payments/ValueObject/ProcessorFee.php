<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

/**
 * What a rail charged us for one transaction — or an explicit statement that
 * it will not say.
 *
 * ── Why "unknown" is a value and not a null ───────────────────────────────
 * Some rails report a per-transaction fee (Paddle, after billing). Others net
 * their cut at settlement and publish nothing per transaction (PayHere). The
 * modelled processing fee booked at checkout has to be reconciled against the
 * real one, and when the real one is unobtainable, the reconciliation must
 * record *that*.
 *
 * A null, or a zero, would flow into the same subtraction as a real figure and
 * come out as "no drift" — a green dashboard and a silent alert describing a
 * comparison that never happened. This codebase has already shipped one
 * heartbeat that stayed green through 28 consecutive silent failures; the
 * lesson was to alert on outcomes, and an outcome of "could not check" has to
 * be representable to be alertable.
 */
final readonly class ProcessorFee
{
    private function __construct(
        public bool   $isKnown,
        public ?Money $amount,
    ) {}

    public static function known(Money $amount): self
    {
        return new self(true, $amount);
    }

    /**
     * The rail does not report a per-transaction fee.
     *
     * Reconciliation must exclude these from drift rather than score them as
     * zero, and the console must show them as unreconciled rather than as
     * matched.
     */
    public static function unknown(): self
    {
        return new self(false, null);
    }

    /** @throws \LogicException when the fee was never reported. */
    public function amountOrFail(): Money
    {
        if (!$this->isKnown || $this->amount === null) {
            throw new \LogicException(
                'This rail does not report a per-transaction fee. Check isKnown before reading it — treating an unreported fee as zero makes every reconciliation report a match it never made.'
            );
        }

        return $this->amount;
    }

    /** @return array{known: bool, minor_units: int|null, currency: string|null} */
    public function toArray(): array
    {
        return [
            'known'       => $this->isKnown,
            'minor_units' => $this->amount?->minorUnits,
            'currency'    => $this->amount?->currency->code,
        ];
    }
}
