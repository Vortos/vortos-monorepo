<?php

declare(strict_types=1);

namespace Vortos\Paddle\Failover;

final class PaddleCircuitBreaker
{
    private PaddleCircuitBreakerState $state         = PaddleCircuitBreakerState::Closed;
    private int                       $consecutiveFailures = 0;
    private float                     $openedAt      = 0.0;

    public function __construct(
        private readonly int $failureThreshold,
        private readonly int $resetTimeoutSeconds,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->state === PaddleCircuitBreakerState::Closed) {
            return true;
        }

        if ($this->state === PaddleCircuitBreakerState::Open) {
            if ((microtime(true) - $this->openedAt) >= $this->resetTimeoutSeconds) {
                $this->state = PaddleCircuitBreakerState::HalfOpen;
                return true;
            }
            return false;
        }

        // HalfOpen — allow one probe through
        return true;
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->state               = PaddleCircuitBreakerState::Closed;
    }

    public function recordFailure(): void
    {
        $this->consecutiveFailures++;

        if ($this->state === PaddleCircuitBreakerState::HalfOpen
            || $this->consecutiveFailures >= $this->failureThreshold
        ) {
            $this->state    = PaddleCircuitBreakerState::Open;
            $this->openedAt = microtime(true);
        }
    }

    public function state(): PaddleCircuitBreakerState
    {
        return $this->state;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }
}
