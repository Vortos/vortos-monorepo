<?php

declare(strict_types=1);

namespace Vortos\Deploy\Gate;

final readonly class GateBudget
{
    public function __construct(
        public float $timeout = 60.0,
        public float $interval = 2.0,
        public int $maxAttempts = 30,
        public float $perRequestTimeout = 5.0,
        /**
         * How many CONSECUTIVE ready probes the color must return before the gate passes. The default
         * of 1 is "ready on first success" (legacy). Requiring more than one closes the flapping-cutover
         * hole: a color whose /health/ready is non-monotonic during warmup (reports ready once, then
         * dips back to 503 for a while under boot contention) would, at 1, trigger an immediate cutover
         * and then drop out from under the edge. Requiring N consecutive passes means traffic only
         * switches once the color has held ready across ~N*interval seconds — i.e. it is genuinely
         * stable, not momentarily lucky. Any failed probe resets the streak.
         */
        public int $requiredConsecutivePasses = 1,
    ) {
        if ($this->timeout <= 0) {
            throw new \InvalidArgumentException('Gate timeout must be positive.');
        }

        if ($this->interval <= 0) {
            throw new \InvalidArgumentException('Gate interval must be positive.');
        }

        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('Gate max attempts must be >= 1.');
        }

        if ($this->perRequestTimeout <= 0) {
            throw new \InvalidArgumentException('Gate per-request timeout must be positive.');
        }

        if ($this->requiredConsecutivePasses < 1) {
            throw new \InvalidArgumentException('Gate required consecutive passes must be >= 1.');
        }
    }

    /**
     * Build a budget whose ONLY effective bound is the wall-clock timeout. The historical default
     * capped maxAttempts at 30, so a raised timeout (e.g. 180s to beat a cold start) was silently
     * clamped back to ~60s by the attempt ceiling — the gate gave up long before the deadline and
     * a slow-but-healthy cold start was misreported as a failure. Deriving maxAttempts from
     * timeout/interval (plus a small margin) makes the timeout the real bound, as intended.
     */
    public static function forTimeout(
        float $timeout,
        float $interval = 2.0,
        float $perRequestTimeout = 5.0,
        int $requiredConsecutivePasses = 1,
    ): self {
        // The deadline must cover both the wait for first-ready AND the stabilization streak that
        // follows it, so the attempt ceiling accounts for the extra consecutive passes on top of the
        // timeout-derived budget.
        $attempts = (int) ceil($timeout / $interval) + $requiredConsecutivePasses + 2;

        return new self(
            timeout: $timeout,
            interval: $interval,
            maxAttempts: max(1, $attempts),
            perRequestTimeout: $perRequestTimeout,
            requiredConsecutivePasses: max(1, $requiredConsecutivePasses),
        );
    }

    /**
     * Build a budget for a color that must reach ready within $timeout AND then hold ready
     * continuously for ~$stabilizationSeconds before the gate passes.
     */
    public static function withStabilization(
        float $timeout,
        float $stabilizationSeconds,
        float $interval = 2.0,
        float $perRequestTimeout = 5.0,
    ): self {
        $passes = $stabilizationSeconds <= 0 ? 1 : ((int) ceil($stabilizationSeconds / $interval) + 1);

        // The deadline covers the cold-start wait plus the full stabilization hold.
        $effectiveTimeout = $timeout + max(0.0, $stabilizationSeconds);

        return self::forTimeout($effectiveTimeout, $interval, $perRequestTimeout, $passes);
    }
}
