<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

/**
 * A refund the rail has accepted.
 *
 * Accepted, not completed — refunds clear over days on most rails, and the
 * terminal outcome arrives on a webhook like everything else. What is
 * guaranteed here is that the rail has taken responsibility for the amount.
 */
final readonly class RefundResult
{
    public function __construct(
        public string $gatewayRefundReference,
        /**
         * What was refunded — null when the rail does not say.
         *
         * A full refund on a rail that neither takes an amount nor reports one
         * back genuinely leaves us without the figure. Null says that; a zero
         * would say "refunded nothing", and a guessed captured total would say
         * something we did not verify. The caller reconciles against the
         * amount it recorded when it captured.
         */
        public ?Money $amount,
        /** Whether the rail reports the money as already returned. */
        public bool   $isImmediate = false,
    ) {
        if (trim($gatewayRefundReference) === '') {
            throw new \InvalidArgumentException('A rail that accepted a refund must return its reference.');
        }
    }
}
