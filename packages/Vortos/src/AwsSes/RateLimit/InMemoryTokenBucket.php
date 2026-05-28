<?php

declare(strict_types=1);

namespace Vortos\AwsSes\RateLimit;

/**
 * Single-process token bucket using hrtime for sub-millisecond accuracy.
 *
 * Refills at $maxRate tokens per second up to $burst capacity.
 * Initialises full (burst tokens available) so a freshly-booted process
 * can send a burst of emails immediately.
 *
 * For multi-process / multi-server deployments use a Redis-backed
 * implementation that stores state in a shared cache.
 */
final class InMemoryTokenBucket implements TokenBucketInterface
{
    private float $tokens;
    private int $lastRefillNs;

    public function __construct(
        private readonly int $maxRate,
        private readonly int $burst,
    ) {
        $this->tokens       = (float) $burst;
        $this->lastRefillNs = hrtime(true);
    }

    public function tryConsume(): bool
    {
        $this->refill();

        if ($this->tokens >= 1.0) {
            $this->tokens -= 1.0;
            return true;
        }

        return false;
    }

    private function refill(): void
    {
        $now         = hrtime(true);
        $elapsedSec  = ($now - $this->lastRefillNs) / 1_000_000_000;
        $added       = $elapsedSec * $this->maxRate;

        $this->tokens       = min((float) $this->burst, $this->tokens + $added);
        $this->lastRefillNs = $now;
    }
}
