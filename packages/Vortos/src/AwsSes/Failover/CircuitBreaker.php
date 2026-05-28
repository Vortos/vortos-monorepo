<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Failover;

/**
 * In-memory circuit breaker for a single SES region.
 *
 * Closed  → Open after failureThreshold consecutive failures.
 * Open    → HalfOpen after resetTimeoutSeconds elapses.
 * HalfOpen→ Closed on success; back to Open on failure.
 */
final class CircuitBreaker
{
    private CircuitBreakerState $state = CircuitBreakerState::Closed;
    private int $consecutiveFailures   = 0;
    private float $openedAt            = 0.0;

    public function __construct(
        private readonly int $failureThreshold,
        private readonly int $resetTimeoutSeconds,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->state === CircuitBreakerState::Open) {
            if ((microtime(true) - $this->openedAt) >= $this->resetTimeoutSeconds) {
                $this->state = CircuitBreakerState::HalfOpen;
                return true;
            }
            return false;
        }

        return true; // Closed and HalfOpen both allow the request
    }

    public function recordSuccess(): void
    {
        $this->state              = CircuitBreakerState::Closed;
        $this->consecutiveFailures = 0;
    }

    public function recordFailure(): void
    {
        ++$this->consecutiveFailures;

        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->state    = CircuitBreakerState::Open;
            $this->openedAt = microtime(true);
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
