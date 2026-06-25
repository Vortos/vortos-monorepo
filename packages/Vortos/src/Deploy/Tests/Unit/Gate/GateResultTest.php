<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Gate;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Gate\GateResult;

final class GateResultTest extends TestCase
{
    public function test_passed_result(): void
    {
        $result = new GateResult(true, 3, 5.2, 200);

        $this->assertTrue($result->passed);
        $this->assertSame(3, $result->attempts);
        $this->assertSame(5.2, $result->elapsed);
        $this->assertSame(200, $result->lastStatusCode);
    }

    public function test_failed_result(): void
    {
        $result = new GateResult(false, 30, 60.0, 503);

        $this->assertFalse($result->passed);
    }

    public function test_to_array(): void
    {
        $result = new GateResult(true, 1, 0.5, 200);
        $array = $result->toArray();

        $this->assertTrue($array['passed']);
        $this->assertSame(1, $array['attempts']);
    }
}
