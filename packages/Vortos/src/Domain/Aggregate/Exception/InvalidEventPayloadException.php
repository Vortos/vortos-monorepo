<?php

declare(strict_types=1);

namespace Vortos\Domain\Aggregate\Exception;

/**
 * Thrown when an object passed to AggregateRoot::recordEvent() violates
 * the event payload shape rules:
 *
 *   F1 — class must be `final`
 *   F2 — all properties must be `public readonly` and constructor-promoted
 *   F3 — class must have no methods other than `__construct`
 *
 * These rules enforce the "events are immutable facts" invariant at the
 * point where events are recorded. Validation runs lazily — once per
 * payload class per process — and the result is cached, so the cost is
 * paid once at startup rather than per event.
 *
 * Caught violations are programmer errors, not runtime failures. Fix the
 * payload class shape; do not catch this exception.
 */
final class InvalidEventPayloadException extends \InvalidArgumentException
{
    public static function notFinal(string $payloadClass): self
    {
        return new self(sprintf(
            'Event payload class "%s" must be declared `final`. '
            . 'Domain events are immutable facts; allowing inheritance breaks event identity and serialization.',
            $payloadClass,
        ));
    }

    public static function hasMethod(string $payloadClass, string $methodName, string $declaredIn): self
    {
        $where = $declaredIn === $payloadClass
            ? 'declared on the class'
            : sprintf('inherited from "%s"', $declaredIn);

        return new self(sprintf(
            'Event payload class "%s" must have no methods other than `__construct`, but "%s()" is %s. '
            . 'Domain events are pure data — behavior belongs on the aggregate or in a service.',
            $payloadClass,
            $methodName,
            $where,
        ));
    }

    public static function badProperty(string $payloadClass, string $propertyName, string $reason): self
    {
        return new self(sprintf(
            'Event payload class "%s" property "$%s" is invalid: %s. '
            . 'All event properties must be `public readonly` and constructor-promoted.',
            $payloadClass,
            $propertyName,
            $reason,
        ));
    }
}
