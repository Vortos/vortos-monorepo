<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\RateLimit\CircuitBreaker\CircuitBreakerState;
use Vortos\Auth\RateLimit\CircuitBreaker\RateLimitCircuitBreaker;

final class RateLimitCircuitBreakerTest extends TestCase
{
    public function test_starts_closed(): void
    {
        $cb = new RateLimitCircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);
        $this->assertSame(CircuitBreakerState::Closed, $cb->state());
        $this->assertTrue($cb->isAvailable());
    }

    public function test_stays_closed_below_threshold(): void
    {
        $cb = new RateLimitCircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreakerState::Closed, $cb->state());
        $this->assertTrue($cb->isAvailable());
        $this->assertSame(2, $cb->consecutiveFailures());
    }

    public function test_opens_at_threshold(): void
    {
        $cb = new RateLimitCircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreakerState::Open, $cb->state());
        $this->assertFalse($cb->isAvailable());
    }

    public function test_success_resets_to_closed(): void
    {
        $cb = new RateLimitCircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordSuccess();
        $this->assertSame(CircuitBreakerState::Closed, $cb->state());
        $this->assertSame(0, $cb->consecutiveFailures());
    }

    public function test_transitions_to_half_open_after_timeout(): void
    {
        $cb = new RateLimitCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 0);
        $cb->recordFailure();
        $this->assertSame(CircuitBreakerState::Open, $cb->state());

        // resetTimeoutSeconds=0 means immediate transition to half-open
        $this->assertTrue($cb->isAvailable());
        $this->assertSame(CircuitBreakerState::HalfOpen, $cb->state());
    }

    public function test_half_open_closes_on_success(): void
    {
        $cb = new RateLimitCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 0);
        $cb->recordFailure();
        $cb->isAvailable(); // triggers half-open
        $cb->recordSuccess();
        $this->assertSame(CircuitBreakerState::Closed, $cb->state());
        $this->assertSame(0, $cb->consecutiveFailures());
    }

    public function test_half_open_reopens_on_failure(): void
    {
        $cb = new RateLimitCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 0);
        $cb->recordFailure();
        $cb->isAvailable(); // triggers half-open
        $cb->recordFailure();
        $this->assertSame(CircuitBreakerState::Open, $cb->state());
    }

    public function test_open_circuit_stays_unavailable_within_timeout(): void
    {
        $cb = new RateLimitCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 3600);
        $cb->recordFailure();
        $this->assertFalse($cb->isAvailable());
        $this->assertSame(CircuitBreakerState::Open, $cb->state());
    }

    public function test_default_threshold_and_reset(): void
    {
        $cb = new RateLimitCircuitBreaker();
        // Default: threshold=5, reset=30s
        for ($i = 0; $i < 4; $i++) {
            $cb->recordFailure();
        }
        $this->assertTrue($cb->isAvailable());
        $cb->recordFailure();
        $this->assertFalse($cb->isAvailable());
    }
}
