<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\CircuitBreaker\LockoutCircuitBreaker;
use Vortos\Auth\RateLimit\CircuitBreaker\CircuitBreakerState;

final class LockoutCircuitBreakerTest extends TestCase
{
    public function test_starts_closed(): void
    {
        $cb = new LockoutCircuitBreaker();
        $this->assertSame(CircuitBreakerState::Closed, $cb->state());
        $this->assertTrue($cb->isAvailable());
    }

    public function test_opens_after_threshold_failures(): void
    {
        $cb = new LockoutCircuitBreaker(failureThreshold: 3);
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertTrue($cb->isAvailable());

        $cb->recordFailure();
        $this->assertSame(CircuitBreakerState::Open, $cb->state());
        $this->assertFalse($cb->isAvailable());
    }

    public function test_success_resets_to_closed(): void
    {
        $cb = new LockoutCircuitBreaker(failureThreshold: 2);
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertFalse($cb->isAvailable());

        // Simulate timeout elapsed — use a 0-second reset for test
        $cb2 = new LockoutCircuitBreaker(failureThreshold: 2, resetTimeoutSeconds: 0);
        $cb2->recordFailure();
        $cb2->recordFailure();
        // After reset timeout, transitions to half-open
        $this->assertTrue($cb2->isAvailable());
        $cb2->recordSuccess();
        $this->assertSame(CircuitBreakerState::Closed, $cb2->state());
    }
}
