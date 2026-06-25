<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe\Capacity;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Capacity\CapacityReader\ProcCapacityReader;

/**
 * Exercises the real I/O seam against the actual host (Docker container). We can't
 * control what disk/proc state the CI box has, so assertions only pin the contract:
 * never throws, returns a sane percentage or an honest null degrade.
 */
final class ProcCapacityReaderTest extends TestCase
{
    public function testDiskUsedPctOfRealPathIsWithinSaneRange(): void
    {
        $value = (new ProcCapacityReader())->diskUsedPct('/');

        self::assertNotNull($value);
        self::assertGreaterThanOrEqual(0.0, $value);
        self::assertLessThanOrEqual(100.0, $value);
    }

    public function testDiskUsedPctOfNonexistentPathDegradesToNull(): void
    {
        self::assertNull((new ProcCapacityReader())->diskUsedPct('/this/path/does/not/exist/at/all'));
    }

    public function testMemoryUsedPctIsWithinSaneRangeWhenProcMeminfoIsReadable(): void
    {
        $reader = new ProcCapacityReader();
        $value = $reader->memoryUsedPct();

        if (!is_readable('/proc/meminfo')) {
            self::assertNull($value);

            return;
        }

        self::assertNotNull($value);
        self::assertGreaterThanOrEqual(0.0, $value);
        self::assertLessThanOrEqual(100.0, $value);
    }

    public function testCpuLoadPctIsNonNegativeWhenDeterminable(): void
    {
        $value = (new ProcCapacityReader())->cpuLoadPct();

        if ($value === null) {
            self::assertTrue(true, 'Undeterminable on this host — honest null degrade is the contract.');

            return;
        }

        self::assertGreaterThanOrEqual(0.0, $value);
    }

    public function testNeverThrowsRegardlessOfInput(): void
    {
        $reader = new ProcCapacityReader();

        self::assertNull($reader->diskUsedPct(''));
        self::assertNull($reader->diskUsedPct("\0invalid"));
    }
}
