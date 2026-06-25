<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe\Capacity;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Capacity\CapacityReader\InMemoryCapacityReader;

final class InMemoryCapacityReaderTest extends TestCase
{
    public function testDefaultsToZero(): void
    {
        $reader = new InMemoryCapacityReader();

        self::assertSame(0.0, $reader->diskUsedPct('/'));
        self::assertSame(0.0, $reader->memoryUsedPct());
        self::assertSame(0.0, $reader->cpuLoadPct());
    }

    public function testWithersReturnIndependentClones(): void
    {
        $base = new InMemoryCapacityReader();
        $disk = $base->withDiskUsedPct(50.0);

        self::assertSame(0.0, $base->diskUsedPct('/'));
        self::assertSame(50.0, $disk->diskUsedPct('/'));
    }

    public function testSupportsNullToSimulateUnreadableProc(): void
    {
        $reader = (new InMemoryCapacityReader())
            ->withDiskUsedPct(null)
            ->withMemoryUsedPct(null)
            ->withCpuLoadPct(null);

        self::assertNull($reader->diskUsedPct('/'));
        self::assertNull($reader->memoryUsedPct());
        self::assertNull($reader->cpuLoadPct());
    }
}
