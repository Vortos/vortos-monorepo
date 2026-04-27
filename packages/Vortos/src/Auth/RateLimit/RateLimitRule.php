<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit;

/**
 * Immutable value object describing a rate limit rule.
 */
final readonly class RateLimitRule
{
    public function __construct(
        public int $limit,
        public int $windowSeconds,
    ) {}

    public static function unlimited(): self
    {
        return new self(PHP_INT_MAX, 60);
    }

    public function isUnlimited(): bool
    {
        return $this->limit === PHP_INT_MAX;
    }
}
