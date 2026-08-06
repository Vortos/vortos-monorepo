<?php

declare(strict_types=1);

namespace Vortos\Payments\Enum;

/**
 * Where a charge stands at the rail, normalised across rails.
 *
 * Every rail spells these differently — Paddle has string statuses, PayHere
 * has signed integers — and the mapping belongs in each adapter so that
 * nothing downstream ever branches on a vendor's vocabulary.
 */
enum TransactionStatus: string
{
    /** Created, not yet paid. The overwhelmingly common state at checkout open. */
    case Pending = 'pending';

    /** Money captured. The only status that may credit a ledger. */
    case Completed = 'completed';

    /** The payer abandoned it. Not an error — no alert, no retry. */
    case Cancelled = 'cancelled';

    /** The rail tried and refused. Terminal for this attempt. */
    case Failed = 'failed';

    /** Money returned to the payer after capture, in whole or in part. */
    case Refunded = 'refunded';

    /**
     * The payer's bank forcibly reversed it. Distinct from a refund because
     * the liability differs: on a merchant-of-record rail the rail absorbs it,
     * on a gateway rail we do.
     */
    case ChargedBack = 'charged_back';

    /**
     * Whether this status means money is, right now, ours to owe onward.
     *
     * Deliberately narrow. Anything that is not plainly a completed capture
     * answers false, so a status this enum gains later cannot start crediting
     * ledgers by accident.
     */
    public function isSettled(): bool
    {
        return $this === self::Completed;
    }

    /** Whether the rail will never move this transaction again. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending => false,
            default       => true,
        };
    }
}
