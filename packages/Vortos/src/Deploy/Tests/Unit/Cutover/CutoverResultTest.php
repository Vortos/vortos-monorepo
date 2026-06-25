<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\CutoverResult;

final class CutoverResultTest extends TestCase
{
    public function test_assert_zero_drops_passes(): void
    {
        $result = new CutoverResult(
            succeeded: true,
            reverted: false,
            drainedConnections: 5,
            forciblyClosed: 0,
            durationMs: 100,
            verifiedLiveUpstream: true,
        );

        $result->assertZeroDrops();
        $this->assertTrue(true);
    }

    public function test_assert_zero_drops_fails(): void
    {
        $result = new CutoverResult(
            succeeded: true,
            reverted: false,
            drainedConnections: 3,
            forciblyClosed: 2,
            durationMs: 100,
            verifiedLiveUpstream: true,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2 forcibly closed');
        $result->assertZeroDrops();
    }

    public function test_to_array(): void
    {
        $result = new CutoverResult(
            succeeded: true,
            reverted: false,
            drainedConnections: 10,
            forciblyClosed: 1,
            durationMs: 250,
            verifiedLiveUpstream: true,
            detail: 'test',
        );

        $arr = $result->toArray();
        $this->assertTrue($arr['succeeded']);
        $this->assertSame(10, $arr['drained_connections']);
        $this->assertSame(1, $arr['forcibly_closed']);
        $this->assertSame(250, $arr['duration_ms']);
        $this->assertTrue($arr['verified_live_upstream']);
    }
}
