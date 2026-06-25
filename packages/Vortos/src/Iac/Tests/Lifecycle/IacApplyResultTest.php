<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacApplyResult;

final class IacApplyResultTest extends TestCase
{
    public function test_is_success_when_no_failures(): void
    {
        $result = new IacApplyResult(3, 0, 1500, 'digest123');
        $this->assertTrue($result->isSuccess());
    }

    public function test_is_not_success_when_failures_exist(): void
    {
        $result = new IacApplyResult(2, 1, 1500, 'digest123');
        $this->assertFalse($result->isSuccess());
    }

    public function test_zero_applied_zero_failed_is_success(): void
    {
        $result = new IacApplyResult(0, 0, 0, 'digest123');
        $this->assertTrue($result->isSuccess());
    }

    public function test_outputs_preserved(): void
    {
        $outputs = ['vpc_id' => 'vpc-123', 'subnet_id' => 'subnet-456'];
        $result = new IacApplyResult(1, 0, 100, 'digest', $outputs);
        $this->assertSame($outputs, $result->outputs);
    }

    public function test_negative_applied_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacApplyResult(-1, 0, 0, 'digest');
    }

    public function test_negative_failed_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacApplyResult(0, -1, 0, 'digest');
    }

    public function test_negative_duration_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacApplyResult(0, 0, -1, 'digest');
    }
}
