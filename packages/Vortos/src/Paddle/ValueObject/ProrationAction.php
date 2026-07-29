<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

/**
 * Which way the money moves when a subscription is repriced mid-period.
 *
 * Paddle reports the proration as an unsigned amount plus this action, so the amount
 * on its own says nothing about whether the customer is being charged or credited.
 * A caller that reads only the amount will tell somebody downgrading that they owe
 * the exact sum they are actually being given back.
 */
enum ProrationAction: string
{
    /** The customer pays the difference now. */
    case Charge = 'charge';

    /** The unused remainder comes back as credit against future invoices. */
    case Credit = 'credit';

    public function isCredit(): bool
    {
        return $this === self::Credit;
    }

    /** The multiplier to apply to an unsigned Paddle amount to make it signed. */
    public function sign(): int
    {
        return $this === self::Credit ? -1 : 1;
    }
}
