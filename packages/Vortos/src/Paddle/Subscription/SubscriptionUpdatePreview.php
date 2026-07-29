<?php

declare(strict_types=1);

namespace Vortos\Paddle\Subscription;

use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\ProrationAction;

/**
 * What repricing a subscription would settle immediately, and what it bills next.
 *
 * The action is not decoration. Paddle reports the immediate proration as an
 * *unsigned* amount and puts the direction in a separate field, so a preview that
 * carries only the amount cannot tell a charge from a credit — and a downgrade,
 * which is the common case for a credit, would be presented to the customer as a
 * bill for the exact sum they are being given back.
 */
final class SubscriptionUpdatePreview
{
    public function __construct(
        public readonly PaddleSubscriptionId $subscriptionId,
        /** Unsigned, as Paddle reports it. Use {@see signedImmediateTotal()} for arithmetic. */
        public readonly string               $immediateTotal,
        public readonly string               $nextBillingTotal,
        public readonly string               $currencyCode,
        /** Defaulted so existing construction sites keep working. */
        public readonly ProrationAction      $immediateAction = ProrationAction::Charge,
    ) {}

    /**
     * The immediate amount with its direction applied: negative when the customer is
     * being credited. Minor units, as a string — these are money, and rounding them
     * through a float is how a bill ends up a penny out.
     */
    public function signedImmediateTotal(): string
    {
        $amount = ltrim($this->immediateTotal, '-');

        if ($amount === '' || $amount === '0') {
            return '0';
        }

        return $this->immediateAction->isCredit() ? '-' . $amount : $amount;
    }

    public function isCredit(): bool
    {
        return $this->immediateAction->isCredit();
    }
}
