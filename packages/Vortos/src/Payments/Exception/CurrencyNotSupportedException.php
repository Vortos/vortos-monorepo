<?php

declare(strict_types=1);

namespace Vortos\Payments\Exception;

use Vortos\Payments\ValueObject\RailCapabilities;

/**
 * A charge was requested in a currency this rail cannot bill.
 *
 * ── Why this is an exception and not a silent conversion ──────────────────
 * Converting here would be the single most expensive mistake this package
 * could make. The organisation is credited what it published; a conversion
 * applied inside a gateway adapter is a conversion nobody priced, nobody
 * snapshotted and nobody disclosed, so the credit silently lands short. If a
 * currency needs converting, the caller converts it deliberately, with a
 * quote it froze — and then charges a currency the rail supports.
 */
final class CurrencyNotSupportedException extends PaymentsException
{
    public static function forRail(string $gatewayId, string $currency, RailCapabilities $capabilities): self
    {
        return new self(sprintf(
            'The %s rail cannot bill %s (it supports %s). Convert deliberately with a frozen quote, or route to a rail that supports it — never convert inside the adapter.',
            $gatewayId,
            $currency,
            implode(', ', $capabilities->supportedCurrencies),
        ));
    }
}
