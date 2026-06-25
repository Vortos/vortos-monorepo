<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Aggregator;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Aggregator\HealthBudget;

final class HealthBudgetTest extends TestCase
{
    public function testValidBudget(): void
    {
        $b = new HealthBudget(3000, 10000, 1000);

        self::assertSame(3000, $b->perProbeDeadlineMs);
        self::assertSame(10000, $b->overallBudgetMs);
        self::assertSame(1000, $b->readyCacheTtlMs);
    }

    public function testDeadlineEqualToBudgetIsValid(): void
    {
        $b = new HealthBudget(5000, 5000, 0);

        self::assertSame(5000, $b->perProbeDeadlineMs);
    }

    public function testZeroCacheTtlIsValid(): void
    {
        $b = new HealthBudget(1000, 5000, 0);

        self::assertSame(0, $b->readyCacheTtlMs);
    }

    public function testPerProbeDeadlineZeroThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('perProbeDeadlineMs');

        new HealthBudget(0, 10000, 1000);
    }

    public function testPerProbeDeadlineNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HealthBudget(-1, 10000, 1000);
    }

    public function testOverallBudgetZeroThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('overallBudgetMs');

        new HealthBudget(1000, 0, 1000);
    }

    public function testOverallBudgetNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HealthBudget(1000, -1, 1000);
    }

    public function testDeadlineExceedsBudgetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('perProbeDeadlineMs must be <= overallBudgetMs');

        new HealthBudget(10001, 10000, 1000);
    }

    public function testNegativeCacheTtlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('readyCacheTtlMs');

        new HealthBudget(1000, 5000, -1);
    }

    public function testDefaultValues(): void
    {
        $b = new HealthBudget();

        self::assertSame(3000, $b->perProbeDeadlineMs);
        self::assertSame(10000, $b->overallBudgetMs);
        self::assertSame(1000, $b->readyCacheTtlMs);
    }
}
