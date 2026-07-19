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
    }

    /**
     * Build a budget whose ONLY effective bound is the wall-clock timeout. The historical default
     * capped maxAttempts at 30, so a raised timeout (e.g. 180s to beat a cold start) was silently
     * clamped back to ~60s by the attempt ceiling — the gate gave up long before the deadline and
     * a slow-but-healthy cold start was misreported as a failure. Deriving maxAttempts from
     * timeout/interval (plus a small margin) makes the timeout the real bound, as intended.
     */
    public static function forTimeout(float $timeout, float $interval = 2.0, float $perRequestTimeout = 5.0): self
    {
        $attempts = (int) ceil($timeout / $interval) + 2;

        return new self(
            timeout: $timeout,
            interval: $interval,
            maxAttempts: max(1, $attempts),
            perRequestTimeout: $perRequestTimeout,
        );
    }
}
