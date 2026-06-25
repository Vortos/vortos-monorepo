<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

final class DeployDoctorTest extends TestCase
{
    use PreflightTestFactory;

    public function test_all_pass_is_clear_with_exit_zero(): void
    {
        $doctor = new DeployDoctor([
            $this->passing('a.one', PreflightCategory::DriverSet),
            $this->passing('b.two', PreflightCategory::Capability),
        ]);

        $report = $doctor->run($this->context());

        $this->assertTrue($report->isClear());
        $this->assertSame(0, $report->exitCode());
        $this->assertSame(2, $report->countByStatus(PreflightStatus::Pass));
    }

    public function test_one_fail_is_not_clear_with_exit_one(): void
    {
        $doctor = new DeployDoctor([
            $this->passing('a.one', PreflightCategory::DriverSet),
            $this->failing('z.bad', PreflightCategory::Credential),
        ]);

        $report = $doctor->run($this->context());

        $this->assertFalse($report->isClear());
        $this->assertSame(1, $report->exitCode());
        $this->assertCount(1, $report->failures());
        $this->assertSame('z.bad', $report->failures()[0]->id);
    }

    public function test_a_throwing_check_is_reported_as_fail_closed(): void
    {
        $doctor = new DeployDoctor([
            $this->passing('a.one', PreflightCategory::DriverSet),
            $this->throwing('x.boom', PreflightCategory::Schema),
        ]);

        $report = $doctor->run($this->context());

        $this->assertFalse($report->isClear(), 'a check that throws must never produce a clear report');
        $failures = $report->failures();
        $this->assertCount(1, $failures);
        $this->assertSame('x.boom', $failures[0]->id);
        $this->assertStringContainsString('could not complete', $failures[0]->summary);
        $this->assertStringContainsString('kaboom', $failures[0]->detail);
    }

    public function test_skip_on_na_gate_still_clear(): void
    {
        $doctor = new DeployDoctor([
            $this->passing('a.one', PreflightCategory::DriverSet),
            $this->skipping('s.na', PreflightCategory::Arch),
        ]);

        $report = $doctor->run($this->context());

        $this->assertTrue($report->isClear());
        $this->assertSame(1, $report->countByStatus(PreflightStatus::Skip));
    }

    public function test_findings_are_sorted_by_category_then_id(): void
    {
        $doctor = new DeployDoctor([
            $this->passing('z.last', PreflightCategory::Plan),
            $this->passing('b.mid', PreflightCategory::Capability),
            $this->passing('a.first', PreflightCategory::DriverSet),
        ]);

        $report = $doctor->run($this->context());

        $ids = array_map(static fn ($f): string => $f->id, $report->findings);
        $this->assertSame(['a.first', 'b.mid', 'z.last'], $ids);
    }

    public function test_empty_check_set_is_clear(): void
    {
        $report = (new DeployDoctor([]))->run($this->context());

        $this->assertTrue($report->isClear());
    }

    private function passing(string $id, PreflightCategory $category): PreflightCheckInterface
    {
        return $this->stub($id, $category, fn () => PreflightFinding::pass($id, $category, 'ok'));
    }

    private function failing(string $id, PreflightCategory $category): PreflightCheckInterface
    {
        return $this->stub($id, $category, fn () => PreflightFinding::fail($id, $category, 'bad'));
    }

    private function skipping(string $id, PreflightCategory $category): PreflightCheckInterface
    {
        return $this->stub($id, $category, fn () => PreflightFinding::skip($id, $category, 'n/a'));
    }

    private function throwing(string $id, PreflightCategory $category): PreflightCheckInterface
    {
        return $this->stub($id, $category, function (): PreflightFinding {
            throw new \RuntimeException('kaboom');
        });
    }

    private function stub(string $id, PreflightCategory $category, \Closure $run): PreflightCheckInterface
    {
        return new class($id, $category, $run) implements PreflightCheckInterface {
            public function __construct(
                private readonly string $id,
                private readonly PreflightCategory $category,
                private readonly \Closure $run,
            ) {}

            public function id(): string
            {
                return $this->id;
            }

            public function category(): PreflightCategory
            {
                return $this->category;
            }

            public function check(PreflightContext $context): PreflightFinding
            {
                return ($this->run)();
            }
        };
    }
}
