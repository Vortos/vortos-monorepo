<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Failover;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Failover\CircuitBreaker;
use Vortos\AwsSes\Failover\CircuitBreakerState;

final class CircuitBreakerTest extends TestCase
{
    public function test_starts_closed(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);
        $this->assertSame(CircuitBreakerState::Closed, $cb->state());
        $this->assertTrue($cb->isAvailable());
    }

    public function test_opens_after_failure_threshold(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);

        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreakerState::Closed, $cb->state());

        $cb->recordFailure(); // threshold reached
        $this->assertSame(CircuitBreakerState::Open, $cb->state());
        $this->assertFalse($cb->isAvailable());
    }

    public function test_success_resets_failure_count(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);

        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordSuccess(); // reset
        $cb->recordFailure();
        $cb->recordFailure();

        $this->assertSame(CircuitBreakerState::Closed, $cb->state());
        $this->assertSame(2, $cb->consecutiveFailures());
    }

    public function test_success_closes_open_circuit(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 60);
        $cb->recordFailure(); // opens
        $this->assertSame(CircuitBreakerState::Open, $cb->state());

        $cb->recordSuccess();
        $this->assertSame(CircuitBreakerState::Closed, $cb->state());
        $this->assertTrue($cb->isAvailable());
    }

    public function test_transitions_to_half_open_after_reset_timeout(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 0);
        $cb->recordFailure(); // opens immediately
        $this->assertSame(CircuitBreakerState::Open, $cb->state());

        // With resetTimeoutSeconds=0, the timeout has already elapsed
        $this->assertTrue($cb->isAvailable()); // allows probe
        $this->assertSame(CircuitBreakerState::HalfOpen, $cb->state());
    }

    public function test_half_open_failure_reopens_circuit(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 0);
        $cb->recordFailure();     // opens
        $cb->isAvailable();       // transitions to HalfOpen
        $cb->recordFailure();     // reopens

        $this->assertSame(CircuitBreakerState::Open, $cb->state());
    }

    public function test_blocked_when_open_within_reset_window(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 3600);
        $cb->recordFailure(); // opens
        $this->assertFalse($cb->isAvailable());
    }
}
