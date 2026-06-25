<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\ProbeStatus;

final class ProbeStatusTest extends TestCase
{
    public function testPassIsHealthy(): void
    {
        self::assertTrue(ProbeStatus::Pass->isHealthy());
    }

    public function testWarnIsHealthy(): void
    {
        self::assertTrue(ProbeStatus::Warn->isHealthy());
    }

    public function testFailIsNotHealthy(): void
    {
        self::assertFalse(ProbeStatus::Fail->isHealthy());
    }

    public function testAllCasesHaveStringValues(): void
    {
        self::assertSame('pass', ProbeStatus::Pass->value);
        self::assertSame('warn', ProbeStatus::Warn->value);
        self::assertSame('fail', ProbeStatus::Fail->value);
    }
}
