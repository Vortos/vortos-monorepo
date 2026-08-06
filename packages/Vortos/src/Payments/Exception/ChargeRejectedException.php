<?php

declare(strict_types=1);

namespace Vortos\Payments\Exception;

/**
 * The rail understood the request and refused it — a bad field, a declined
 * card, an amount below the rail's floor.
 *
 * Terminal for this attempt. Retrying the identical request produces the
 * identical refusal, so a caller that retries on this is building a loop.
 */
final class ChargeRejectedException extends PaymentsException
{
    public function __construct(
        string $message,
        public readonly string $gatewayId,
        /** The rail's own code, kept verbatim for support and reconciliation. */
        public readonly ?string $gatewayCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
