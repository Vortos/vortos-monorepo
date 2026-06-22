<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Delivery;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Delivery\CircuitBreakerFlagStorage;
use Vortos\FeatureFlags\Delivery\CircuitState;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

final class CircuitBreakerFlagStorageTest extends TestCase
{
    // ── Closed state ──

    public function test_delegates_to_inner_when_closed(): void
    {
        $flags   = [$this->flag('f1')];
        $inner   = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')->willReturn($flags);

        $breaker = new CircuitBreakerFlagStorage($inner);

        $this->assertSame($flags, $breaker->findAll());
        $this->assertSame(CircuitState::Closed, $breaker->circuitState());
    }

    public function test_caches_last_known_good_on_success(): void
    {
        $flags = [$this->flag('f1')];
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')
            ->willReturnOnConsecutiveCalls(
                $flags,
                $this->throwException(new \RuntimeException('db down')),
                $this->throwException(new \RuntimeException('db down')),
                $this->throwException(new \RuntimeException('db down')),
            );

        $breaker = new CircuitBreakerFlagStorage($inner, failureThreshold: 3);

        // First call succeeds — caches snapshot
        $this->assertCount(1, $breaker->findAll());

        // Three failures → opens breaker, returns last known good
        $breaker->findAll();
        $breaker->findAll();
        $result = $breaker->findAll();

        $this->assertSame(CircuitState::Open, $breaker->circuitState());
        $this->assertCount(1, $result);
        $this->assertSame('f1', $result[0]->name);
    }

    // ── Opening ──

    public function test_opens_after_threshold_consecutive_failures(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')->willThrowException(new \RuntimeException('fail'));

        $breaker = new CircuitBreakerFlagStorage($inner, failureThreshold: 2);

        $breaker->findAll(); // failure 1
        $this->assertSame(CircuitState::Closed, $breaker->circuitState());

        $breaker->findAll(); // failure 2 → opens
        $this->assertSame(CircuitState::Open, $breaker->circuitState());
    }

    public function test_does_not_open_on_intermittent_failures(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('fail')),
                [$this->flag('f1')], // success resets counter
                $this->throwException(new \RuntimeException('fail')),
            );

        $breaker = new CircuitBreakerFlagStorage($inner, failureThreshold: 2);

        $breaker->findAll(); // failure 1
        $breaker->findAll(); // success → resets
        $breaker->findAll(); // failure 1 (counter reset)

        $this->assertSame(CircuitState::Closed, $breaker->circuitState());
    }

    // ── Open state — last-known-good ──

    public function test_open_breaker_returns_empty_when_no_snapshot(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')->willThrowException(new \RuntimeException('fail'));

        $breaker = new CircuitBreakerFlagStorage($inner, failureThreshold: 1);

        $result = $breaker->findAll();
        $this->assertSame([], $result);
    }

    public function test_open_breaker_does_not_call_inner(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->expects($this->exactly(3))
            ->method('findAll')
            ->willThrowException(new \RuntimeException('fail'));

        $breaker = new CircuitBreakerFlagStorage($inner, failureThreshold: 3, cooldownSeconds: 999.0);

        $breaker->findAll(); // 1
        $breaker->findAll(); // 2
        $breaker->findAll(); // 3 → opens

        // These should NOT call inner (circuit is open and cooldown hasn't elapsed)
        $breaker->findAll();
        $breaker->findAll();
    }

    // ── findByName degradation ──

    public function test_find_by_name_returns_from_snapshot_when_open(): void
    {
        $flags = [$this->flag('target-flag')];
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')
            ->willReturnOnConsecutiveCalls(
                $flags,
                $this->throwException(new \RuntimeException('fail')),
            );
        $inner->method('findByName')
            ->willThrowException(new \RuntimeException('fail'));

        $breaker = new CircuitBreakerFlagStorage($inner, failureThreshold: 1, cooldownSeconds: 999.0);

        $breaker->findAll(); // cache snapshot
        $breaker->findAll(); // fail → opens

        $result = $breaker->findByName('target-flag');
        $this->assertNotNull($result);
        $this->assertSame('target-flag', $result->name);
    }

    public function test_find_by_name_returns_null_for_unknown_flag_in_snapshot(): void
    {
        $flags = [$this->flag('other-flag')];
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')
            ->willReturnOnConsecutiveCalls(
                $flags,
                $this->throwException(new \RuntimeException('fail')),
            );
        $inner->method('findByName')
            ->willThrowException(new \RuntimeException('fail'));

        $breaker = new CircuitBreakerFlagStorage($inner, failureThreshold: 1, cooldownSeconds: 999.0);

        $breaker->findAll();
        $breaker->findAll(); // opens

        $this->assertNull($breaker->findByName('nonexistent'));
    }

    // ── Write-through ──

    public function test_save_always_delegates_to_inner(): void
    {
        $flag  = $this->flag('f1');
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->expects($this->once())->method('save')->with($flag);

        $breaker = new CircuitBreakerFlagStorage($inner);
        $breaker->save($flag);
    }

    public function test_save_rethrows_on_failure(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('save')->willThrowException(new \RuntimeException('write fail'));

        $breaker = new CircuitBreakerFlagStorage($inner);

        $this->expectException(\RuntimeException::class);
        $breaker->save($this->flag('f1'));
    }

    public function test_delete_always_delegates_to_inner(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->expects($this->once())->method('delete')->with('f1');

        $breaker = new CircuitBreakerFlagStorage($inner);
        $breaker->delete('f1');
    }

    // ── Consecutive failures counter ──

    public function test_failure_counter_tracks_consecutive_failures(): void
    {
        $inner = $this->createMock(FlagStorageInterface::class);
        $inner->method('findAll')->willThrowException(new \RuntimeException('fail'));

        $breaker = new CircuitBreakerFlagStorage($inner, failureThreshold: 10);

        $breaker->findAll();
        $this->assertSame(1, $breaker->consecutiveFailures());

        $breaker->findAll();
        $this->assertSame(2, $breaker->consecutiveFailures());
    }

    // ── Helper ──

    private function flag(string $name = 'test-flag'): FeatureFlag
    {
        return new FeatureFlag(
            id: 'id-1', name: $name, description: 'test', enabled: true,
            rules: [], variants: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        );
    }
}
