<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacDestroyResult;

final class IacDestroyResultTest extends TestCase
{
    public function test_is_success_when_no_failures(): void
    {
        $result = new IacDestroyResult(5, 0, 2000);
        $this->assertTrue($result->isSuccess());
    }

    public function test_is_not_success_when_failures_exist(): void
    {
        $result = new IacDestroyResult(3, 2, 2000);
        $this->assertFalse($result->isSuccess());
    }

    public function test_zero_destroyed_zero_failed_is_success(): void
    {
        $result = new IacDestroyResult(0, 0, 0);
        $this->assertTrue($result->isSuccess());
    }

    public function test_negative_destroyed_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacDestroyResult(-1, 0, 0);
    }

    public function test_negative_failed_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacDestroyResult(0, -1, 0);
    }

    public function test_negative_duration_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacDestroyResult(0, 0, -1);
    }
}
