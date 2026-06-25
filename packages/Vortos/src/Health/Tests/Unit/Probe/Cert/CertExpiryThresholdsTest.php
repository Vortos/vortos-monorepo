<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe\Cert;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Cert\CertExpiryThresholds;
use Vortos\Health\Probe\ProbeStatus;

/**
 * Boundary table (§7): 15/14/8/7/2/1/0/expired → none/Warn/Warn/Warn/Warn/Crit/Crit/Crit.
 */
final class CertExpiryThresholdsTest extends TestCase
{
    /** @return iterable<string, array{int, ProbeStatus}> */
    public static function boundaryProvider(): iterable
    {
        yield '15 days (above warn)' => [15, ProbeStatus::Pass];
        yield '14 days (warn boundary)' => [14, ProbeStatus::Warn];
        yield '8 days' => [8, ProbeStatus::Warn];
        yield '7 days (second-warn boundary)' => [7, ProbeStatus::Warn];
        yield '2 days' => [2, ProbeStatus::Warn];
        yield '1 day (critical boundary)' => [1, ProbeStatus::Fail];
        yield '0 days' => [0, ProbeStatus::Fail];
        yield 'already expired (-5 days)' => [-5, ProbeStatus::Fail];
    }

    /** @dataProvider boundaryProvider */
    public function testStatusForBoundary(int $days, ProbeStatus $expected): void
    {
        self::assertSame($expected, (new CertExpiryThresholds())->statusFor($days));
    }

    public function testRejectsNonPositiveCriticalDays(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CertExpiryThresholds(criticalDays: 0);
    }

    public function testRejectsSecondWarnNotGreaterThanCritical(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CertExpiryThresholds(secondWarnDays: 1, criticalDays: 1);
    }

    public function testRejectsWarnNotGreaterThanSecondWarn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CertExpiryThresholds(warnDays: 7, secondWarnDays: 7);
    }
}
