<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\LockoutCheckResult;
use Vortos\Auth\Lockout\LockoutConfig;
use Vortos\Auth\Lockout\LockoutFailureMode;
use Vortos\Auth\Lockout\LockoutKeyNormalizer;
use Vortos\Auth\Lockout\LockoutManager;
use Vortos\Auth\Lockout\LockoutTrack;
use Vortos\Auth\Lockout\Contract\LockoutStoreInterface;
use Vortos\Auth\Lockout\Exception\LockoutUnavailableException;

final class LockoutResilienceTest extends TestCase
{
    private function makeManager(
        LockoutFailureMode $failureMode = LockoutFailureMode::FailClosed,
    ): array {
        $store = $this->createMock(LockoutStoreInterface::class);
        $config = (new LockoutConfig())
            ->maxAttempts(5)
            ->lockDurationSeconds(900)
            ->trackBy(LockoutTrack::Email);
        return [$store, new LockoutManager($store, $config, new LockoutKeyNormalizer(), $failureMode)];
    }

    public function test_fail_closed_throws_when_store_unavailable(): void
    {
        [$store, $manager] = $this->makeManager(LockoutFailureMode::FailClosed);
        $store->method('isLocked')->willReturn(LockoutCheckResult::unavailable());

        $this->expectException(LockoutUnavailableException::class);
        $manager->isLocked('user@example.com', '10.0.0.1');
    }

    public function test_fail_open_returns_false_when_store_unavailable(): void
    {
        [$store, $manager] = $this->makeManager(LockoutFailureMode::FailOpen);
        $store->method('isLocked')->willReturn(LockoutCheckResult::unavailable());

        $this->assertFalse($manager->isLocked('user@example.com', '10.0.0.1'));
    }

    public function test_locked_result_returns_true(): void
    {
        [$store, $manager] = $this->makeManager();
        $store->method('isLocked')->willReturn(LockoutCheckResult::locked());
        $store->method('getAttemptCount')->willReturn(5);
        $store->method('getRemainingTtl')->willReturn(600);

        $this->assertTrue($manager->isLocked('user@example.com', '10.0.0.1'));
    }

    public function test_not_locked_result_returns_false(): void
    {
        [$store, $manager] = $this->makeManager();
        $store->method('isLocked')->willReturn(LockoutCheckResult::notLocked());
        $store->method('getAttemptCount')->willReturn(0);

        $this->assertFalse($manager->isLocked('user@example.com', '10.0.0.1'));
    }

    public function test_record_failed_attempt_tolerates_store_failure(): void
    {
        [$store, $manager] = $this->makeManager();
        $store->method('incrementAttempts')->willReturn(-1);
        $store->expects($this->never())->method('lock');

        // Should not throw — recording is best-effort
        $manager->recordFailedAttempt('user@example.com', '10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_record_failed_attempt_locks_on_threshold_with_positive_count(): void
    {
        [$store, $manager] = $this->makeManager();
        $store->method('incrementAttempts')->willReturn(5);
        $store->expects($this->once())->method('lock');

        $manager->recordFailedAttempt('user@example.com', '10.0.0.1');
    }
}
