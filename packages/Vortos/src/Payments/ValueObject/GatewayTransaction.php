<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

use Vortos\Payments\Enum\TransactionStatus;

/**
 * The rail's current view of one charge.
 *
 * This is the answer to "did it actually settle" — the question asked when a
 * webhook was missed, when a payer swears they paid, and by every
 * reconciliation job. It is authoritative in a way a browser return URL never
 * is, because it comes from the rail over an authenticated channel rather than
 * from the payer's address bar.
 */
final readonly class GatewayTransaction
{
    public function __construct(
        /** Our reference, as the rail echoes it. Null when the rail did not store one. */
        public ?string           $reference,
        public string            $gatewayReference,
        public TransactionStatus $status,
        /** What the payer was billed. */
        public Money             $total,
        /** Populated only once settled; rails know their fee after the fact, not at checkout. */
        public ?PayoutTotals     $payout = null,
        public ?\DateTimeImmutable $settledAt = null,
    ) {
        if ($status->isSettled() && $settledAt === null) {
            throw new \InvalidArgumentException(
                'A settled transaction needs a settlement time; reconciliation buckets by day and cannot date it itself without inventing provenance.'
            );
        }
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }
}
