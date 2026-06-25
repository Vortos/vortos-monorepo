<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacDriftReport;

final class IacDriftReportTest extends TestCase
{
    public function test_clean_has_no_drift(): void
    {
        $report = IacDriftReport::clean();
        $this->assertFalse($report->hasDrift);
        $this->assertFalse($report->unreachable);
        $this->assertStringContainsString('No infrastructure drift', $report->summary);
    }

    public function test_drifted_has_drift(): void
    {
        $report = IacDriftReport::drifted('3 resources changed');
        $this->assertTrue($report->hasDrift);
        $this->assertFalse($report->unreachable);
        $this->assertSame('3 resources changed', $report->summary);
    }

    public function test_unreachable_has_drift_and_unreachable(): void
    {
        $report = IacDriftReport::unreachable('connection refused');
        $this->assertTrue($report->hasDrift);
        $this->assertTrue($report->unreachable);
        $this->assertSame('connection refused', $report->summary);
    }

    public function test_constructor_custom_values(): void
    {
        $report = new IacDriftReport(true, 'manual', false);
        $this->assertTrue($report->hasDrift);
        $this->assertSame('manual', $report->summary);
        $this->assertFalse($report->unreachable);
    }
}
