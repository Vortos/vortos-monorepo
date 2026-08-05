<?php

declare(strict_types=1);

namespace Vortos\Paddle\Transaction;

/**
 * What Paddle actually kept, and what is left for the seller.
 *
 * Everything else on a transaction describes what the *buyer* was charged —
 * which the application already knows, because it computed it. This is the only
 * place the API reports the other side of the trade.
 *
 * ── Why it matters more than it looks ─────────────────────────────────────
 * Paddle does not expose its fee before a transaction is billed: a pre-billing
 * preview returns subtotal, discount, tax and total, and no fee at all. Any
 * platform that adds its own fee on top therefore has to *model* Paddle's cut
 * from the published rate card at checkout, and check that model against
 * reality afterwards. This struct is the second half of that loop. Without it
 * the modelled fee can never be verified and the error accumulates silently.
 *
 * ── Nullability is load-bearing ───────────────────────────────────────────
 * `Transaction::$payoutTotals` is null until the transaction is billed. Callers
 * must be able to tell "not settled yet" from "settled, fee was zero": a
 * reconciler that reads a missing fee as zero books the entire modelled amount
 * as drift and credits the seller money nobody returned. So this is never
 * synthesised with zeroes — absent stays absent.
 *
 * ── Currency ──────────────────────────────────────────────────────────────
 * `currencyCode` is the **payout** currency, which need not be the currency the
 * buyer was charged in. Comparing a fee across the two without converting is
 * meaningless, so the currency travels with the numbers rather than being
 * assumed by the caller.
 *
 * Amounts are minor-unit integer strings, as Paddle sends them. They are kept
 * as strings rather than cast: the caller knows the currency's exponent and
 * whether it wants an int or a decimal, and a lossy cast in the mapper is not
 * something a caller can undo.
 */
final class PayoutTotals
{
    public function __construct(
        public readonly string $subtotal,
        public readonly string $discount,
        public readonly string $tax,
        public readonly string $total,
        public readonly string $credit,
        public readonly string $balance,
        public readonly string $grandTotal,
        /** Paddle's fee, in minor units of `currencyCode`. */
        public readonly string $fee,
        /** What is left for the seller after the fee, in minor units. */
        public readonly string $earnings,
        /** The payout currency — not necessarily the buyer's charge currency. */
        public readonly string $currencyCode,
        /** Fee as a decimal fraction string, e.g. "0.05". */
        public readonly string $feeRate,
        /** Charge currency → payout currency, when they differ. */
        public readonly string $exchangeRate,
    ) {}

    public static function fromSdk(\Paddle\SDK\Entities\Shared\TransactionPayoutTotals $sdk): self
    {
        return new self(
            subtotal:     $sdk->subtotal,
            discount:     $sdk->discount,
            tax:          $sdk->tax,
            total:        $sdk->total,
            credit:       $sdk->credit,
            balance:      $sdk->balance,
            grandTotal:   $sdk->grandTotal,
            fee:          $sdk->fee,
            earnings:     $sdk->earnings,
            // The SDK models this as a MyCLabs enum whose backing property is
            // protected; __toString is the public way to read it.
            currencyCode: (string) $sdk->currencyCode,
            feeRate:      $sdk->feeRate,
            exchangeRate: $sdk->exchangeRate,
        );
    }

    /** The fee as an integer count of minor units. */
    public function feeMinor(): int
    {
        return (int) $this->fee;
    }

    /** The seller's share as an integer count of minor units. */
    public function earningsMinor(): int
    {
        return (int) $this->earnings;
    }
}
