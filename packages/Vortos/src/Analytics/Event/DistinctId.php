<?php

declare(strict_types=1);

namespace Vortos\Analytics\Event;

use InvalidArgumentException;

/**
 * The only identifier that ever crosses the analytics boundary.
 *
 * Deliberately a thin, validated wrapper around a single string — never a bag of
 * traits — so "what crosses the process boundary" stays a one-line audit. No PII
 * is enforced *in the id itself* by convention (callers should pass a user/anon id,
 * never an email); PII carried in traits/properties is the redactor's job.
 */
final readonly class DistinctId
{
    public const MAX_LENGTH = 200;

    public function __construct(public string $value)
    {
        if ($this->value === '') {
            throw new InvalidArgumentException('DistinctId must not be empty.');
        }

        if (strlen($this->value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'DistinctId must not exceed %d bytes, got %d.',
                self::MAX_LENGTH,
                strlen($this->value),
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
