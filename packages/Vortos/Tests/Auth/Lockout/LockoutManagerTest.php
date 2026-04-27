<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\LockoutConfig;
use Vortos\Auth\Lockout\LockoutManager;
use Vortos\Auth\Lockout\LockoutTrack;
use Vortos\Auth\Lockout\Contract\LockoutStoreInterface;

final class LockoutManagerTest extends TestCase
{
    private function makeManager(int $maxAttempts = 5, LockoutTrack $track = LockoutTrack::Email): array
    {
        $store = $this->createMock(LockoutStoreInterface::class);
        $config = (new LockoutConfig())
            ->maxAttempts($maxAttempts)
            ->lockDurationSeconds(900)
            ->trackBy($track);
        return [$store, new LockoutManager($store, $config)];
    }

    public function test_is_locked_returns_false_when_not_locked(): void
    {
        [$store, $manager] = $this->makeManager();
        $store->method('isLocked')->willReturn(false);
        $this->assertFalse($manager->isLocked('user@example.com', '10.0.0.1'));
    }

    public function test_is_locked_returns_true_when_email_locked(): void
    {
        [$store, $manager] = $this->makeManager();
        $store->method('isLocked')->willReturnMap([
            ['email', 'user@example.com', true],
            ['ip', '10.0.0.1', false],
        ]);
        $this->assertTrue($manager->isLocked('user@example.com', '10.0.0.1'));
    }

    public function test_record_failed_attempt_locks_after_max_attempts(): void
    {
        [$store, $manager] = $this->makeManager(3);
        $store->method('incrementAttempts')->willReturn(3);
        $store->expects($this->once())->method('lock');
        $manager->recordFailedAttempt('user@example.com', '10.0.0.1');
    }

    public function test_record_failed_attempt_does_not_lock_before_max(): void
    {
        [$store, $manager] = $this->makeManager(5);
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
        $store->method('isLocked')->willReturnMap([
            ['ip', '10.0.0.1', true],
        ]);
        $this->assertTrue($manager->isLocked('user@example.com', '10.0.0.1'));
    }

    public function test_both_tracking_checks_email_and_ip(): void
    {
        [$store, $manager] = $this->makeManager(5, LockoutTrack::Both);
        $store->method('incrementAttempts')->willReturn(6);
        $store->expects($this->exactly(2))->method('lock'); // once for email, once for ip
        $manager->recordFailedAttempt('user@example.com', '10.0.0.1');
    }
}
