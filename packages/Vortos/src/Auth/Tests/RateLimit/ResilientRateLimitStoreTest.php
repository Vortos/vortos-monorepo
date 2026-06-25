<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\RateLimit\CircuitBreaker\CircuitBreakerState;
use Vortos\Auth\RateLimit\CircuitBreaker\RateLimitCircuitBreaker;
use Vortos\Auth\RateLimit\Contract\RateLimitStoreInterface;
use Vortos\Auth\RateLimit\Exception\RateLimitStoreUnavailableException;
use Vortos\Auth\RateLimit\Storage\ResilientRateLimitStore;

final class ResilientRateLimitStoreTest extends TestCase
{
    private RateLimitStoreInterface $inner;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(RateLimitStoreInterface::class);
    }

    public function test_delegates_increment_on_success(): void
    {
        $this->inner->method('increment')->willReturn(3);
        $store = $this->makeStore();

        $this->assertSame(3, $store->increment('key', 60));
        $this->assertSame(CircuitBreakerState::Closed, $store->circuitBreaker()->state());
    }

    public function test_delegates_get_ttl_on_success(): void
    {
        $this->inner->method('getTtl')->willReturn(45);
        $store = $this->makeStore();

        $this->assertSame(45, $store->getTtl('key'));
    }

    public function test_delegates_reset_on_success(): void
    {
        $this->inner->expects($this->once())->method('reset')->with('key');
        $store = $this->makeStore();

        $store->reset('key');
    }

    public function test_records_failure_and_rethrows(): void
    {
        $this->inner->method('increment')
            ->willThrowException(new RateLimitStoreUnavailableException('fail'));
        $store = $this->makeStore(threshold: 5);

        try {
            $store->increment('key', 60);
            $this->fail('Expected exception');
        } catch (RateLimitStoreUnavailableException) {
        }

        $this->assertSame(1, $store->circuitBreaker()->consecutiveFailures());
        $this->assertSame(CircuitBreakerState::Closed, $store->circuitBreaker()->state());
    }

    public function test_circuit_opens_after_threshold_failures(): void
    {
        $this->inner->method('increment')
            ->willThrowException(new RateLimitStoreUnavailableException('fail'));
        $store = $this->makeStore(threshold: 3);

        for ($i = 0; $i < 3; $i++) {
            try {
                $store->increment('key', 60);
            } catch (RateLimitStoreUnavailableException) {
            }
        }

        $this->assertSame(CircuitBreakerState::Open, $store->circuitBreaker()->state());
    }

    public function test_open_circuit_throws_without_calling_inner(): void
    {
        $this->inner->method('increment')
            ->willThrowException(new RateLimitStoreUnavailableException('fail'));
        $store = $this->makeStore(threshold: 1, resetSeconds: 3600);

        try {
            $store->increment('key', 60);
        } catch (RateLimitStoreUnavailableException) {
        }

        $this->assertSame(CircuitBreakerState::Open, $store->circuitBreaker()->state());

        // Inner should not be called when circuit is open
        $this->inner->expects($this->never())->method('increment');

        $this->expectException(RateLimitStoreUnavailableException::class);
        $this->expectExceptionMessage('circuit breaker is open');
        $store->increment('key', 60);
    }

    public function test_success_after_failure_resets_circuit(): void
    {
        $callCount = 0;
        $this->inner->method('increment')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 2) {
                throw new RateLimitStoreUnavailableException('fail');
            }
            return 1;
        });

        $store = $this->makeStore(threshold: 5);

        for ($i = 0; $i < 2; $i++) {
            try {
                $store->increment('key', 60);
            } catch (RateLimitStoreUnavailableException) {
            }
        }

        $this->assertSame(2, $store->circuitBreaker()->consecutiveFailures());

        // Third call succeeds
        $store->increment('key', 60);
        $this->assertSame(CircuitBreakerState::Closed, $store->circuitBreaker()->state());
        $this->assertSame(0, $store->circuitBreaker()->consecutiveFailures());
    }

    public function test_get_ttl_throws_when_circuit_open(): void
    {
        $this->inner->method('increment')
            ->willThrowException(new RateLimitStoreUnavailableException('fail'));
        $store = $this->makeStore(threshold: 1, resetSeconds: 3600);

        try {
            $store->increment('key', 60);
        } catch (RateLimitStoreUnavailableException) {
        }

        $this->expectException(RateLimitStoreUnavailableException::class);
        $store->getTtl('key');
    }

    public function test_reset_throws_when_circuit_open(): void
    {
        $this->inner->method('increment')
            ->willThrowException(new RateLimitStoreUnavailableException('fail'));
        $store = $this->makeStore(threshold: 1, resetSeconds: 3600);

        try {
            $store->increment('key', 60);
        } catch (RateLimitStoreUnavailableException) {
        }

        $this->expectException(RateLimitStoreUnavailableException::class);
        $store->reset('key');
    }

    private function makeStore(int $threshold = 5, int $resetSeconds = 30): ResilientRateLimitStore
    {
        return new ResilientRateLimitStore(
            $this->inner,
            new RateLimitCircuitBreaker($threshold, $resetSeconds),
        );
    }
}
