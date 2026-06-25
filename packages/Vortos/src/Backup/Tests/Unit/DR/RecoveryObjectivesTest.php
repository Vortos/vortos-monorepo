<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DR;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\DR\RecoveryObjectives;

final class RecoveryObjectivesTest extends TestCase
{
    public function test_rto_exceeded_true_when_above_threshold(): void
    {
        $obj = new RecoveryObjectives(300, 1800);
        $this->assertTrue($obj->rtoExceeded(1800001)); // 1800 seconds + 1ms
    }

    public function test_rto_exceeded_false_when_within_threshold(): void
    {
        $obj = new RecoveryObjectives(300, 1800);
        $this->assertFalse($obj->rtoExceeded(1000000)); // 1000 seconds
    }

    public function test_rto_exceeded_false_at_exact_threshold(): void
    {
        $obj = new RecoveryObjectives(300, 1800);
        $this->assertFalse($obj->rtoExceeded(1800000)); // exactly 1800 seconds
    }

    public function test_rejects_negative_rpo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecoveryObjectives(-1, 1800);
    }

    public function test_rejects_negative_rto(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecoveryObjectives(300, -1);
    }
}
