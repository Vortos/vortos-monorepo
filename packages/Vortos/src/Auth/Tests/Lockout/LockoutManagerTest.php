<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\LockoutCheckResult;
use Vortos\Auth\Lockout\LockoutConfig;
use Vortos\Auth\Lockout\LockoutKeyNormalizer;
use Vortos\Auth\Lockout\LockoutManager;
use Vortos\Auth\Lockout\LockoutResult;
use Vortos\Auth\Lockout\LockoutTrack;
use Vortos\Auth\Lockout\LockoutFailureMode;
use Vortos\Auth\Lockout\Contract\LockoutStoreInterface;
use Vortos\Auth\Lockout\Exception\LockoutUnavailableException;

final class LockoutManagerTest extends TestCase
{
    private LockoutKeyNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new LockoutKeyNormalizer();
    }

    private function makeManager(
        int $maxAttempts = 5,
        LockoutTrack $track = LockoutTrack::Both,
        LockoutFailureMode $failureMode = LockoutFailureMode::FailClosed,
    ): array {
        $store = $this->createMock(LockoutStoreInterface::class);
        $config = (new LockoutConfig())
            ->maxAttempts($maxAttempts)
            ->lockDurationSeconds(900)
            ->trackBy($track);
        return [$store, new LockoutManager($store, $config, $this->normalizer, $failureMode)];
    }

    public function test_check_returns_clear_when_not_locked(): void
    {
        [$store, $manager] = $this->makeManager();
        $store->method('isLocked')->willReturn(LockoutCheckResult::notLocked());
        $store->method('getAttemptCount')->willReturn(0);
        $result = $manager->check('user@example.com', '10.0.0.1');
        $this->assertFalse($result->locked);
        $this->assertSame(0, $result->backoffSeconds);
    }

    public function test_check_returns_locked_when_email_locked_and_ip_has_attempts(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Both);
        $emailKey = $this->normalizer->normalize('email', 'user@example.com');
        $ipKey = $this->normalizer->normalize('ip', '10.0.0.1');

        $store->method('isLocked')->willReturnCallback(function (string $type, string $value) use ($emailKey, $ipKey) {
            if ($type === 'email' && $value === $emailKey) return LockoutCheckResult::locked();
            if ($type === 'ip' && $value === $ipKey) return LockoutCheckResult::notLocked();
            return LockoutCheckResult::notLocked();
        });
        $store->method('getAttemptCount')->willReturnCallback(function (string $type, string $value) use ($ipKey) {
            if ($type === 'ip' && $value === $ipKey) return 3;
            return 5;
        });
        $store->method('getRemainingTtl')->willReturn(600);

        $result = $manager->check('user@example.com', '10.0.0.1');
        $this->assertTrue($result->locked);
    }

    public function test_check_returns_backoff_when_email_locked_but_ip_clean(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Both);
        $emailKey = $this->normalizer->normalize('email', 'user@example.com');
        $ipKey = $this->normalizer->normalize('ip', '10.0.0.1');

        $store->method('isLocked')->willReturnCallback(function (string $type, string $value) use ($emailKey) {
            if ($type === 'email' && $value === $emailKey) return LockoutCheckResult::locked();
            return LockoutCheckResult::notLocked();
        });
        $store->method('getAttemptCount')->willReturnCallback(function (string $type, string $value) use ($ipKey) {
            if ($type === 'ip' && $value === $ipKey) return 0;
            return 5;
        });

        $result = $manager->check('user@example.com', '10.0.0.1');
        $this->assertFalse($result->locked);
        $this->assertTrue($result->shouldDelay());
    }

    public function test_record_failed_attempt_locks_after_max_attempts(): void
    {
        [$store, $manager] = $this->makeManager(3, LockoutTrack::Both);
        $store->method('incrementAttempts')->willReturn(3);
        $store->expects($this->exactly(2))->method('lock');
        $manager->recordFailedAttempt('user@example.com', '10.0.0.1');
    }

    public function test_record_failed_attempt_does_not_lock_before_max(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Email);
        $store->method('incrementAttempts')->willReturn(3);
        $store->expects($this->never())->method('lock');
        $manager->recordFailedAttempt('user@example.com', '10.0.0.1');
    }

    public function test_clear_lockout_clears_attempts(): void
    {
        [$store, $manager] = $this->makeManager();
        $store->expects($this->atLeastOnce())->method('clearAttempts');
        $manager->clearLockout('user@example.com', '10.0.0.1');
    }

    public function test_get_message_returns_config_message(): void
    {
        [$store, $manager] = $this->makeManager();
        $this->assertNotEmpty($manager->getMessage());
    }

    public function test_ip_tracking_checks_ip(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Ip);
        $ipKey = $this->normalizer->normalize('ip', '10.0.0.1');
        $store->method('isLocked')->willReturnCallback(function (string $type, string $value) use ($ipKey) {
            if ($type === 'ip' && $value === $ipKey) return LockoutCheckResult::locked();
            return LockoutCheckResult::notLocked();
        });
        $store->method('getAttemptCount')->willReturn(0);
        $store->method('getRemainingTtl')->willReturn(300);

        $result = $manager->check('user@example.com', '10.0.0.1');
        $this->assertTrue($result->locked);
    }

    public function test_both_tracking_checks_email_and_ip(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Both);
        $store->method('incrementAttempts')->willReturn(6);
        $store->expects($this->exactly(2))->method('lock');
        $manager->recordFailedAttempt('user@example.com', '10.0.0.1');
    }

    public function test_case_insensitive_email_lockout(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Email);

        $normalizedKey = $this->normalizer->normalize('email', 'victim@example.com');
        $incrementCalls = [];
        $store->method('incrementAttempts')->willReturnCallback(function (string $type, string $value) use (&$incrementCalls) {
            $incrementCalls[] = $value;
            return count($incrementCalls);
        });

        $manager->recordFailedAttempt('Victim@Example.COM', '10.0.0.1');
        $manager->recordFailedAttempt('victim@example.com', '10.0.0.1');

        $this->assertSame($incrementCalls[0], $incrementCalls[1]);
        $this->assertSame($normalizedKey, $incrementCalls[0]);
    }

    public function test_exponential_backoff_progression(): void
    {
        [$store, $manager] = $this->makeManager(10, LockoutTrack::Email);

        $store->method('isLocked')->willReturn(LockoutCheckResult::notLocked());
        $store->method('getAttemptCount')->willReturnOnConsecutiveCalls(0, 1, 2, 3, 4, 5);

        $r0 = $manager->check('user@example.com', '10.0.0.1');
        $this->assertFalse($r0->shouldDelay());

        $r1 = $manager->check('user@example.com', '10.0.0.1');
        $this->assertFalse($r1->shouldDelay());

        $r2 = $manager->check('user@example.com', '10.0.0.1');
        $this->assertFalse($r2->shouldDelay());

        $r3 = $manager->check('user@example.com', '10.0.0.1');
        $this->assertSame(1, $r3->backoffSeconds);

        $r4 = $manager->check('user@example.com', '10.0.0.1');
        $this->assertSame(2, $r4->backoffSeconds);

        $r5 = $manager->check('user@example.com', '10.0.0.1');
        $this->assertSame(4, $r5->backoffSeconds);
    }

    public function test_backoff_capped_at_max(): void
    {
        $store = $this->createMock(LockoutStoreInterface::class);
        $config = (new LockoutConfig())
            ->maxAttempts(100)
            ->lockDurationSeconds(900)
            ->trackBy(LockoutTrack::Email)
            ->backoffMaxSeconds(60);
        $manager = new LockoutManager($store, $config, $this->normalizer);

        $store->method('isLocked')->willReturn(LockoutCheckResult::notLocked());
        $store->method('getAttemptCount')->willReturn(20);

        $result = $manager->check('user@example.com', '10.0.0.1');
        $this->assertSame(60, $result->backoffSeconds);
    }

    public function test_fail_closed_throws_on_unavailable(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Email, LockoutFailureMode::FailClosed);
        $store->method('isLocked')->willReturn(LockoutCheckResult::unavailable());

        $this->expectException(LockoutUnavailableException::class);
        $manager->check('user@example.com', '10.0.0.1');
    }

    public function test_fail_open_returns_clear_on_unavailable(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Email, LockoutFailureMode::FailOpen);
        $store->method('isLocked')->willReturn(LockoutCheckResult::unavailable());
        $store->method('getAttemptCount')->willReturn(0);

        $result = $manager->check('user@example.com', '10.0.0.1');
        $this->assertFalse($result->locked);
    }

    public function test_is_locked_delegates_to_check(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Email);
        $emailKey = $this->normalizer->normalize('email', 'user@example.com');
        $store->method('isLocked')->willReturnCallback(function (string $type, string $value) use ($emailKey) {
            if ($type === 'email' && $value === $emailKey) return LockoutCheckResult::locked();
            return LockoutCheckResult::notLocked();
        });
        $store->method('getAttemptCount')->willReturn(5);
        $store->method('getRemainingTtl')->willReturn(600);

        $this->assertTrue($manager->isLocked('user@example.com', '10.0.0.1'));
    }

    public function test_default_track_is_both(): void
    {
        $config = new LockoutConfig();
        $this->assertSame(LockoutTrack::Both, $config->trackBy);
    }
}
