<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe\Capacity;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Capacity\CapacitySample;

final class CapacitySampleTest extends TestCase
{
    public function testRejectsEmptyResourceName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CapacitySample('', 50.0);
    }

    public function testRejectsNegativeUsedPct(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CapacitySample('disk', -1.0);
    }

    public function testRejectsImplausiblyHighUsedPct(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CapacitySample('disk', 201.0);
    }

    public function testAcceptsSanePercentage(): void
    {
        $sample = new CapacitySample('disk', 87.3);

        self::assertSame('disk', $sample->resourceName);
        self::assertSame(87.3, $sample->usedPct);
    }
}
