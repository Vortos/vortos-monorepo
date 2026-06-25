<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

final readonly class CanaryWindow
{
    public function __construct(
        /** Total analysis window (e.g. 300 = 5 minutes of metrics). */
        public int $windowSeconds,
        /** Prometheus scrape/step interval (e.g. 15). */
        public int $stepSeconds,
        /** Minimum samples before any verdict is issued. */
        public int $minSamples,
        /** Consecutive breach intervals required before a Rollback is issued. */
        public int $breachIntervals,
        /** Seconds from first Hold/Inconclusive verdict before we fail-closed (→Rollback). */
        public int $holdDeadlineSeconds,
    ) {
        if ($minSamples < 1) {
            throw new \InvalidArgumentException(sprintf('minSamples must be >= 1, got %d.', $minSamples));
        }
        if ($breachIntervals < 1) {
            throw new \InvalidArgumentException(sprintf('breachIntervals must be >= 1, got %d.', $breachIntervals));
        }
        if ($stepSeconds < 1) {
            throw new \InvalidArgumentException(sprintf('stepSeconds must be >= 1, got %d.', $stepSeconds));
        }
        if ($windowSeconds < $stepSeconds) {
            throw new \InvalidArgumentException(sprintf(
                'windowSeconds (%d) must be >= stepSeconds (%d).',
                $windowSeconds,
                $stepSeconds,
            ));
        }
        if ($holdDeadlineSeconds < 0) {
            throw new \InvalidArgumentException(sprintf('holdDeadlineSeconds must be >= 0, got %d.', $holdDeadlineSeconds));
        }
    }

    public static function default(): self
    {
        return new self(
            windowSeconds: 300,
            stepSeconds: 15,
            minSamples: 10,
            breachIntervals: 3,
            holdDeadlineSeconds: 600,
        );
    }
}
