<?php

declare(strict_types=1);

namespace Vortos\Payments\Exception;

/**
 * A webhook payload did not carry a signature this rail's secret can produce.
 *
 * Treat every instance as hostile. The messages below deliberately describe
 * the *class* of failure and never echo the received signature, the expected
 * one, or any part of the payload: an endpoint that tells an attacker how
 * close they got is an oracle.
 */
final class SignatureVerificationException extends PaymentsException
{
    public static function missing(string $gatewayId): self
    {
        return new self(sprintf('%s webhook carried no signature.', $gatewayId));
    }

    public static function mismatch(string $gatewayId): self
    {
        return new self(sprintf('%s webhook signature did not verify.', $gatewayId));
    }

    public static function malformed(string $gatewayId, string $what): self
    {
        return new self(sprintf('%s webhook signature is malformed: %s.', $gatewayId, $what));
    }

    public static function stale(string $gatewayId, int $ageSeconds, int $toleranceSeconds): self
    {
        return new self(sprintf(
            '%s webhook is %ds old, beyond the %ds replay tolerance.',
            $gatewayId,
            $ageSeconds,
            $toleranceSeconds,
        ));
    }
}
