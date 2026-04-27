<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\LockoutConfig;
use Vortos\Auth\Lockout\LockoutTrack;

final class LockoutConfigTest extends TestCase
{
    public function test_default_max_attempts_is_five(): void
    {
        $config = new LockoutConfig();
        $this->assertSame(5, $config->maxAttempts);
    }

    public function test_default_lock_duration_is_900(): void
    {
        $config = new LockoutConfig();
        $this->assertSame(900, $config->lockDurationSeconds);
    }

    public function test_default_track_by_is_email(): void
    {
        $config = new LockoutConfig();
        $this->assertSame(LockoutTrack::Email, $config->trackBy);
    }

    public function test_fluent_max_attempts(): void
    {
        $config = (new LockoutConfig())->maxAttempts(10);
        $this->assertSame(10, $config->maxAttempts);
    }

    public function test_fluent_lock_duration(): void
    {
        $config = (new LockoutConfig())->lockDurationSeconds(1800);
        $this->assertSame(1800, $config->lockDurationSeconds);
    }

    public function test_fluent_track_by(): void
    {
        $config = (new LockoutConfig())->trackBy(LockoutTrack::Both);
        $this->assertSame(LockoutTrack::Both, $config->trackBy);
    }

    public function test_fluent_message(): void
    {
        $config = (new LockoutConfig())->message('Custom message');
        $this->assertSame('Custom message', $config->message);
    }
}
