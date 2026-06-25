<?php

declare(strict_types=1);

namespace Vortos\Analytics\Bridge;

use InvalidArgumentException;

/**
 * Deterministic, **consistent** sampling for the flag-exposure bridge: a given
 * `(contextKey, flag)` pair is always in or out of the sample for a fixed rate — so
 * an A/B cohort stays unbiased across repeated exposures (naive per-event random
 * sampling would bias significance, since the same user could flip sides between
 * calls). Mirrors the hashing style of FF rollout bucketing for conceptual
 * consistency.
 */
final readonly class FlagExposureSampler
{
    private const BUCKETS = 10000;

    public function __construct(private float $rate)
    {
        if ($this->rate < 0.0 || $this->rate > 1.0) {
            throw new InvalidArgumentException(sprintf('Sample rate must be within [0.0, 1.0], got %f.', $this->rate));
        }
    }

    public function isSampledIn(string $contextKey, string $flag): bool
    {
        if ($this->rate <= 0.0) {
            return false;
        }

        if ($this->rate >= 1.0) {
            return true;
        }

        $bucket = crc32($contextKey . '|' . $flag) % self::BUCKETS;
        $threshold = (int) round($this->rate * self::BUCKETS);

        return $bucket < $threshold;
    }

    public function rate(): float
    {
        return $this->rate;
    }
}
