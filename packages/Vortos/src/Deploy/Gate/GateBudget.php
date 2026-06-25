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
}
