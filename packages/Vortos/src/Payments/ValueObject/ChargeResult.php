<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

/**
 * A checkout the rail has accepted and is waiting for the payer to complete.
 *
 * Not a payment. Nothing here says money moved — that only ever arrives on a
 * verified webhook. What this carries is the pair of identifiers that let the
 * two sides find each other afterwards: `reference` is ours, echoed back;
 * `gatewayReference` is theirs, which is what support, refunds and
 * reconciliation are keyed on.
 */
final readonly class ChargeResult
{
    public function __construct(
        /** Our reference, as sent. */
        public string              $reference,
        /** The rail's own identifier for this charge. */
        public string              $gatewayReference,
        /** What the payer will be asked for, exactly as priced. */
        public Money               $total,
        public CheckoutInstruction $checkout,
    ) {
        if (trim($gatewayReference) === '') {
            throw new \InvalidArgumentException(
                'A rail that accepted a charge must return its own reference; without it the payment cannot be refunded or reconciled.'
            );
        }
    }
}
