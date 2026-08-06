<?php

declare(strict_types=1);

namespace Vortos\Payments\Exception;

/**
 * The rail could not be reached, or refused to answer, for a reason that may
 * resolve on its own — a timeout, a 5xx, an open circuit breaker.
 *
 * Distinct from a rejected charge: this one is worth retrying and worth
 * routing around, and it says nothing about whether the payer's money moved.
 * A caller that sees this after a create must treat the charge as *unknown*,
 * not as failed, and reconcile by reference before charging again.
 */
final class GatewayUnavailableException extends PaymentsException
{
    public static function for(string $gatewayId, string $reason, ?\Throwable $previous = null): self
    {
        return new self(sprintf('%s is unavailable: %s', $gatewayId, $reason), 0, $previous);
    }
}
