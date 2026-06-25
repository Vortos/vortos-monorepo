<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\LockoutResult;

final class LockoutResultTest extends TestCase
{
    public function test_locked_result(): void
    {
        $result = LockoutResult::locked(120);

        $this->assertTrue($result->locked);
        $this->assertSame(120, $result->backoffSeconds);
        $this->assertFalse($result->unavailable);
    }

    public function test_backoff_result(): void
    {
        $result = LockoutResult::backoff(8);

        $this->assertFalse($result->locked);
        $this->assertSame(8, $result->backoffSeconds);
        $this->assertTrue($result->shouldDelay());
        $this->assertFalse($result->unavailable);
    }

    public function test_clear_result(): void
    {
        $result = LockoutResult::clear();

        $this->assertFalse($result->locked);
        $this->assertSame(0, $result->backoffSeconds);
        $this->assertFalse($result->shouldDelay());
        $this->assertFalse($result->unavailable);
    }

    public function test_unavailable_result(): void
    {
        $result = LockoutResult::unavailable();

        $this->assertFalse($result->locked);
        $this->assertSame(0, $result->backoffSeconds);
        $this->assertTrue($result->unavailable);
    }

    public function test_should_delay_false_when_zero_backoff(): void
    {
        $result = LockoutResult::backoff(0);
        $this->assertFalse($result->shouldDelay());
    }
}
