<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Preflight\PreflightReport;
use Vortos\Deploy\Preflight\PreflightStatus;

final class PreflightReportTest extends TestCase
{
    public function test_clear_when_no_failures(): void
    {
        $report = new PreflightReport('prod', [
            PreflightFinding::pass('a', PreflightCategory::DriverSet, 'ok'),
            PreflightFinding::skip('b', PreflightCategory::Arch, 'n/a'),
        ]);

        $this->assertTrue($report->isClear());
        $this->assertSame(0, $report->exitCode());
    }

    public function test_not_clear_with_a_failure(): void
    {
        $report = new PreflightReport('prod', [
            PreflightFinding::fail('a', PreflightCategory::Credential, 'bad'),
        ]);

        $this->assertFalse($report->isClear());
        $this->assertSame(1, $report->exitCode());
    }

    public function test_findings_sorted_byte_stable(): void
    {
        $a = new PreflightReport('prod', [
            PreflightFinding::pass('z', PreflightCategory::Plan, 'ok'),
            PreflightFinding::pass('a', PreflightCategory::DriverSet, 'ok'),
        ]);
        $b = new PreflightReport('prod', [
            PreflightFinding::pass('a', PreflightCategory::DriverSet, 'ok'),
            PreflightFinding::pass('z', PreflightCategory::Plan, 'ok'),
        ]);

        $this->assertSame($a->toJson(), $b->toJson(), 'JSON must be byte-stable regardless of input order');
    }

    public function test_schema_version_present(): void
    {
        $report = new PreflightReport('prod', []);
        $array = $report->toArray();

        $this->assertSame(PreflightReport::SCHEMA_VERSION, $array['schema_version']);
        $this->assertSame('prod', $array['env']);
        $this->assertArrayHasKey('summary', $array);
    }

    public function test_summary_counts(): void
    {
        $report = new PreflightReport('prod', [
            PreflightFinding::pass('a', PreflightCategory::DriverSet, 'ok'),
            PreflightFinding::pass('b', PreflightCategory::Capability, 'ok'),
            PreflightFinding::fail('c', PreflightCategory::Credential, 'no'),
            PreflightFinding::skip('d', PreflightCategory::Arch, 'na'),
        ]);

        $this->assertSame(2, $report->countByStatus(PreflightStatus::Pass));
        $this->assertSame(1, $report->countByStatus(PreflightStatus::Fail));
        $this->assertSame(1, $report->countByStatus(PreflightStatus::Skip));
    }
}
