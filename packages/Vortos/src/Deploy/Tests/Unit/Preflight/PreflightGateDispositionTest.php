<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Preflight\PreflightReport;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\OpsKit\Gate\GateDisposition;

/**
 * The deploy gate refuses a release only on failures the release itself is responsible for.
 *
 * Before dispositions existed every Fail refused the release, so a check reading live runtime state
 * vetoed the deploy that would have cured it (C14, C15). These tests pin the replacement contract.
 */
final class PreflightGateDispositionTest extends TestCase
{
    use PreflightTestFactory;

    public function test_an_advisory_failure_does_not_refuse_the_release_but_is_reported(): void
    {
        $report = (new DeployDoctor([
            $this->check('config.ok', GateDisposition::Blocking, 'pass'),
            $this->check('runtime.backlog', GateDisposition::Advisory, 'fail'),
        ]))->run($this->context());

        self::assertTrue($report->isClear());
        self::assertSame(0, $report->exitCode());
        self::assertSame([], $report->failures());
        self::assertCount(1, $report->advisories());
        self::assertSame('runtime.backlog', $report->advisories()[0]->id);
    }

    public function test_strict_mode_refuses_on_advisory_failures_too(): void
    {
        $report = (new DeployDoctor([
            $this->check('runtime.backlog', GateDisposition::Advisory, 'fail'),
        ]))->run($this->context(), strict: true);

        self::assertFalse($report->isClear());
        self::assertSame(['runtime.backlog'], array_map(static fn (PreflightFinding $f): string => $f->id, $report->failures()));
        self::assertSame([], $report->advisories());
    }

    public function test_a_blocking_failure_refuses_regardless_of_advisories_beside_it(): void
    {
        $report = (new DeployDoctor([
            $this->check('config.broken', GateDisposition::Blocking, 'fail'),
            $this->check('runtime.backlog', GateDisposition::Advisory, 'fail'),
        ]))->run($this->context());

        self::assertFalse($report->isClear());
        self::assertSame(['config.broken'], array_map(static fn (PreflightFinding $f): string => $f->id, $report->failures()));
        self::assertCount(1, $report->advisories());
    }

    public function test_the_doctor_stamps_every_finding_with_its_checks_disposition(): void
    {
        $report = (new DeployDoctor([
            $this->check('a.advisory', GateDisposition::Advisory, 'pass'),
            $this->check('b.blocking', GateDisposition::Blocking, 'pass'),
        ]))->run($this->context());

        $byId = [];
        foreach ($report->findings as $finding) {
            $byId[$finding->id] = $finding->declaredDisposition;
        }

        self::assertSame(GateDisposition::Advisory, $byId['a.advisory']);
        self::assertSame(GateDisposition::Blocking, $byId['b.blocking']);
    }

    public function test_a_blocking_check_may_narrow_one_finding_to_advisory(): void
    {
        // The aggregate case: scheduler.doctor is a blocking check that reports runtime-state-only
        // failures as advisory. The doctor must not overwrite that with the check's declaration.
        $narrowing = $this->check('agg.mixed', GateDisposition::Blocking, 'fail', narrowTo: GateDisposition::Advisory);

        $report = (new DeployDoctor([$narrowing]))->run($this->context());

        self::assertTrue($report->isClear());
        self::assertSame(GateDisposition::Advisory, $report->findings[0]->disposition());
    }

    public function test_an_advisory_check_that_throws_stays_advisory(): void
    {
        $report = (new DeployDoctor([
            $this->check('runtime.flaky', GateDisposition::Advisory, 'throw'),
        ]))->run($this->context());

        self::assertTrue($report->isClear(), 'a runtime-state check that cannot answer must not veto a release');
        self::assertCount(1, $report->advisories());
        self::assertStringContainsString('could not complete', $report->advisories()[0]->summary);
    }

    public function test_a_blocking_check_that_throws_refuses(): void
    {
        $report = (new DeployDoctor([
            $this->check('config.flaky', GateDisposition::Blocking, 'throw'),
        ]))->run($this->context());

        self::assertFalse($report->isClear());
    }

    public function test_a_check_that_cannot_state_its_disposition_is_treated_as_blocking(): void
    {
        $check = new class implements PreflightCheckInterface {
            public function id(): string
            {
                return 'broken.declaration';
            }

            public function category(): PreflightCategory
            {
                return PreflightCategory::Plan;
            }

            public function disposition(): GateDisposition
            {
                throw new \LogicException('cannot say');
            }

            public function check(PreflightContext $context): PreflightFinding
            {
                return PreflightFinding::fail($this->id(), $this->category(), 'failed');
            }
        };

        $report = (new DeployDoctor([$check]))->run($this->context());

        self::assertFalse($report->isClear(), 'fail closed: an unknown disposition blocks');
    }

    public function test_a_finding_that_never_passed_through_the_doctor_is_blocking(): void
    {
        $finding = PreflightFinding::fail('raw', PreflightCategory::Plan, 'failed');

        self::assertNull($finding->declaredDisposition);
        self::assertSame(GateDisposition::Blocking, $finding->disposition());
        self::assertFalse((new PreflightReport('production', [$finding]))->isClear());
    }

    public function test_the_json_report_carries_dispositions_and_counts_advisories_separately(): void
    {
        $report = (new DeployDoctor([
            $this->check('config.broken', GateDisposition::Blocking, 'fail'),
            $this->check('runtime.backlog', GateDisposition::Advisory, 'fail'),
            $this->check('config.ok', GateDisposition::Blocking, 'pass'),
        ]))->run($this->context());

        $json = json_decode($report->toJson(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('1.1', $json['schema_version']);
        self::assertFalse($json['clear']);
        self::assertSame(['pass' => 1, 'fail' => 1, 'advisory' => 1, 'skip' => 0], $json['summary']);

        $dispositions = array_column($json['findings'], 'disposition', 'id');
        self::assertSame('blocking', $dispositions['config.broken']);
        self::assertSame('advisory', $dispositions['runtime.backlog']);
    }

    /** @param 'pass'|'fail'|'throw' $outcome */
    private function check(
        string $id,
        GateDisposition $disposition,
        string $outcome,
        ?GateDisposition $narrowTo = null,
    ): PreflightCheckInterface {
        return new class($id, $disposition, $outcome, $narrowTo) implements PreflightCheckInterface {
            public function __construct(
                private readonly string $id,
                private readonly GateDisposition $disposition,
                private readonly string $outcome,
                private readonly ?GateDisposition $narrowTo,
            ) {}

            public function id(): string
            {
                return $this->id;
            }

            public function category(): PreflightCategory
            {
                return PreflightCategory::Plan;
            }

            public function disposition(): GateDisposition
            {
                return $this->disposition;
            }

            public function check(PreflightContext $context): PreflightFinding
            {
                $finding = match ($this->outcome) {
                    'pass' => PreflightFinding::pass($this->id, PreflightCategory::Plan, 'ok'),
                    'fail' => PreflightFinding::fail($this->id, PreflightCategory::Plan, 'failed'),
                    'throw' => throw new \RuntimeException('kaboom'),
                };

                return $this->narrowTo !== null ? $finding->withDisposition($this->narrowTo) : $finding;
            }
        };
    }
}
