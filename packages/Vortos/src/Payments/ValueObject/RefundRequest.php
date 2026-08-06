<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

/**
 * A request to return money to a payer.
 *
 * Carries an `idempotencyKey` because a refund is the one operation where a
 * retry after a timeout can genuinely pay someone twice. The rails that accept
 * such a key use it; the rails that do not are protected by the caller's own
 * store keyed on the same value.
 */
final readonly class RefundRequest
{
    public function __construct(
        public string  $gatewayReference,
        /** Partial refunds are ordinary; null means the full captured amount. */
        public ?Money  $amount,
        public string  $reason,
        public string  $idempotencyKey,
    ) {
        if (trim($gatewayReference) === '') {
            throw new \InvalidArgumentException('A refund needs the rail-side reference of the charge it reverses.');
        }

        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException(
                'A refund needs an idempotency key; a retried refund without one is how a payer gets their money back twice.'
            );
        }

        if ($amount !== null && $amount->isZero()) {
            throw new \InvalidArgumentException('Refusing to refund zero; pass null for a full refund.');
        }
    }

    public function isFullRefund(): bool
    {
        return $this->amount === null;
    }
}
