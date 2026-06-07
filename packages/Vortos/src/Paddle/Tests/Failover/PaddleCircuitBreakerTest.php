<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Failover;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Failover\PaddleCircuitBreaker;
use Vortos\Paddle\Failover\PaddleCircuitBreakerState;

final class PaddleCircuitBreakerTest extends TestCase
{
    public function test_starts_closed(): void
    {
        $cb = new PaddleCircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);
        $this->assertSame(PaddleCircuitBreakerState::Closed, $cb->state());
        $this->assertTrue($cb->isAvailable());
    }

    public function test_opens_after_failure_threshold(): void
    {
        $cb = new PaddleCircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);

        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(PaddleCircuitBreakerState::Closed, $cb->state());

        $cb->recordFailure();
        $this->assertSame(PaddleCircuitBreakerState::Open, $cb->state());
        $this->assertFalse($cb->isAvailable());
    }

    public function test_success_resets_failure_count(): void
    {
        $cb = new PaddleCircuitBreaker(failureThreshold: 3, resetTimeoutSeconds: 60);

        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordSuccess();
        $cb->recordFailure();
        $cb->recordFailure();

        $this->assertSame(PaddleCircuitBreakerState::Closed, $cb->state());
        $this->assertSame(2, $cb->consecutiveFailures());
    }

    public function test_success_closes_open_circuit(): void
    {
        $cb = new PaddleCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 60);
        $cb->recordFailure();
        $this->assertSame(PaddleCircuitBreakerState::Open, $cb->state());

        $cb->recordSuccess();
        $this->assertSame(PaddleCircuitBreakerState::Closed, $cb->state());
        $this->assertTrue($cb->isAvailable());
    }

    public function test_transitions_to_half_open_after_reset_timeout(): void
    {
        $cb = new PaddleCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 0);
        $cb->recordFailure();
        $this->assertSame(PaddleCircuitBreakerState::Open, $cb->state());

        $this->assertTrue($cb->isAvailable());
        $this->assertSame(PaddleCircuitBreakerState::HalfOpen, $cb->state());
    }

    public function test_half_open_failure_reopens_circuit(): void
    {
        $cb = new PaddleCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 0);
        $cb->recordFailure();
        $cb->isAvailable(); // transition to HalfOpen
        $cb->recordFailure();

        $this->assertSame(PaddleCircuitBreakerState::Open, $cb->state());
    }

    public function test_blocked_when_open_within_reset_window(): void
    {
        $cb = new PaddleCircuitBreaker(failureThreshold: 1, resetTimeoutSeconds: 3600);
        $cb->recordFailure();
        $this->assertFalse($cb->isAvailable());
    }
}
