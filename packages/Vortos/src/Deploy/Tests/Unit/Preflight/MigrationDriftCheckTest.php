<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\Check\MigrationDriftCheck;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Migration\Safety\SchemaDriftAuditorInterface;
use Vortos\Migration\Safety\SchemaDriftFinding;

final class MigrationDriftCheckTest extends TestCase
{
    use PreflightTestFactory;

    public function test_id_is_stable(): void
    {
        $check = new MigrationDriftCheck($this->auditorWith([]));
        $this->assertSame('migration.drift', $check->id());
    }

    public function test_category_is_plan(): void
    {
        $check = new MigrationDriftCheck($this->auditorWith([]));
        $this->assertSame(PreflightCategory::Plan, $check->category());
    }

    public function test_pass_when_no_drift(): void
    {
        $check = new MigrationDriftCheck($this->auditorWith([]));
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_fail_when_drift_detected(): void
    {
        $findings = [
            new SchemaDriftFinding('TestModule', true, false, 'missing tables: [users]'),
        ];
        $check = new MigrationDriftCheck($this->auditorWith($findings));
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('1 module', $finding->summary);
        $this->assertStringContainsString('TestModule', $finding->detail);
    }

    public function test_fail_closed_when_auditor_throws(): void
    {
        $auditor = $this->createMock(SchemaDriftAuditorInterface::class);
        $auditor->method('audit')->willThrowException(new \RuntimeException('DB down'));

        $check = new MigrationDriftCheck($auditor);
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('unreachable', strtolower($finding->summary));
    }

    public function test_unreachable_finding_listed_in_detail(): void
    {
        $findings = [
            new SchemaDriftFinding('BrokenModule', true, true, 'DB unreachable'),
        ];
        $check = new MigrationDriftCheck($this->auditorWith($findings));
        $finding = $check->check($this->context());

        $this->assertStringContainsString('[unreachable]', $finding->detail);
    }

    /** @param list<SchemaDriftFinding> $findings */
    private function auditorWith(array $findings): SchemaDriftAuditorInterface
    {
        $auditor = $this->createMock(SchemaDriftAuditorInterface::class);
        $auditor->method('audit')->willReturn($findings);

        return $auditor;
    }
}
