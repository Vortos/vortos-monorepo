<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\CircuitBreaker;

final class RateLimitCircuitBreaker
{
    private CircuitBreakerState $state = CircuitBreakerState::Closed;
    private int $consecutiveFailures = 0;
    private int $openedAt = 0;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $resetTimeoutSeconds = 30,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->state === CircuitBreakerState::Open) {
            if ((time() - $this->openedAt) >= $this->resetTimeoutSeconds) {
                $this->state = CircuitBreakerState::HalfOpen;
                return true;
            }
            return false;
        }

        return true;
    }

    public function recordSuccess(): void
    {
        $this->state = CircuitBreakerState::Closed;
        $this->consecutiveFailures = 0;
    }

    public function recordFailure(): void
    {
        ++$this->consecutiveFailures;

        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->state = CircuitBreakerState::Open;
            $this->openedAt = time();
        }
    }

    public function state(): CircuitBreakerState
    {
        return $this->state;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }
}
