<?php

declare(strict_types=1);

namespace Vortos\Payments\Exception;

/**
 * The rail cannot refund programmatically, or cannot refund this much.
 *
 * Over-refunding is refused rather than clamped: a caller asking for more than
 * was captured has lost track of what it captured, and quietly refunding the
 * smaller amount hides that from everyone including the reconciliation that
 * would otherwise catch it.
 */
final class RefundNotSupportedException extends PaymentsException
{
    public static function byRail(string $gatewayId): self
    {
        return new self(sprintf('%s does not support programmatic refunds; refund it in the merchant portal and record it.', $gatewayId));
    }

    public static function exceedsCaptured(string $gatewayId, int $requestedMinor, int $capturedMinor, string $currency): self
    {
        return new self(sprintf(
            'Refusing to refund %d minor %s on %s: only %d was captured.',
            $requestedMinor,
            $currency,
            $gatewayId,
            $capturedMinor,
        ));
    }
}
